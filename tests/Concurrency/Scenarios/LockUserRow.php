<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Modules\Identity\Contracts\Users;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;

/**
 * Two workers, each with its own connection, and one user row. The order is
 * fixed by signals, not sleeps; the only waits are MySQL's own.
 *
 * Arguments: user_id (int), case:
 *
 * - "exclusion": worker 0 locks the row through Users::lockForUpdate(),
 *   writes platform_role, and holds the lock. Worker 1 then probes the row
 *   with FOR UPDATE NOWAIT (error 3572 while it is held), and calls
 *   Users::lockForUpdate() with a one-second lock-wait timeout (error 1205
 *   while it is held). Worker 0 then ends its transaction (`end`: "commit"
 *   or "rollback"), and worker 1 locks the row again, which must succeed,
 *   and reads the role worker 0 left.
 *
 * - "current-state": worker 1 opens a transaction and reads the role with a
 *   plain read, which fixes its snapshot. Worker 0 then sets the role and
 *   commits. Worker 1 locks the row through Users::lockForUpdate(), which
 *   must return the committed role, and reads it again with a plain read,
 *   which still shows the snapshot.
 *
 * Errors not listed here are not caught, so they fail the worker and the
 * test.
 */
final class LockUserRow implements Scenario
{
    private const int LOCK_NOWAIT = 3572;

    private const int LOCK_WAIT_TIMEOUT = 1205;

    public function prepare(Application $app, int $worker, array $arguments): void
    {
        DB::connection()->getPdo();
        $app->make(Users::class);
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        $users = $app->make(Users::class);
        $userId = (int) $arguments['user_id'];

        return match ((string) $arguments['case']) {
            'exclusion' => $worker === 0
                ? self::holdAndEnd($users, $userId, (string) $arguments['end'], $context)
                : self::contend($users, $userId, $context),
            'current-state' => $worker === 0
                ? self::commitRole($users, $userId, $context)
                : self::readAcrossCommit($users, $userId, $context),
            default => throw new RuntimeException('Unknown case.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function holdAndEnd(Users $users, int $userId, string $end, WorkerContext $context): array
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        $locked = $users->lockForUpdate($userId);
        $connection->table('users')->where('id', $userId)->update(['platform_role' => 'finance']);
        $context->signal('held');

        $context->waitFor('contended', 30.0);

        match ($end) {
            'commit' => $connection->commit(),
            'rollback' => $connection->rollBack(),
            default => throw new RuntimeException('Unknown end.'),
        };

        $context->signal('ended');

        return ['locked_role' => $locked->platformRole?->value, 'level_after_end' => $connection->transactionLevel()];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contend(Users $users, int $userId, WorkerContext $context): array
    {
        $connection = DB::connection();
        $context->waitFor('held');

        $connection->beginTransaction();
        $nowait = self::errorCodeOf(fn () => $connection->select('SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT', [$userId]));

        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $timedOut = self::errorCodeOf(fn () => $users->lockForUpdate($userId));
        $connection->rollBack();

        $context->signal('contended');
        $context->waitFor('ended');

        $connection->beginTransaction();
        $after = $users->lockForUpdate($userId);
        $connection->commit();

        return [
            'nowait_error' => $nowait,
            'contract_wait_error' => $timedOut,
            'role_after_release' => $after->platformRole?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commitRole(Users $users, int $userId, WorkerContext $context): array
    {
        $connection = DB::connection();
        $context->waitFor('snapshot');

        $connection->beginTransaction();
        $users->lockForUpdate($userId);
        $connection->table('users')->where('id', $userId)->update(['platform_role' => 'operations']);
        $connection->commit();

        $context->signal('committed');

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function readAcrossCommit(Users $users, int $userId, WorkerContext $context): array
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        $before = self::plainRole($connection, $userId);
        $context->signal('snapshot');
        $context->waitFor('committed');

        $locked = $users->lockForUpdate($userId);
        $plainAfter = self::plainRole($connection, $userId);
        $connection->commit();

        return [
            'snapshot_role' => $before,
            'locked_role' => $locked->platformRole?->value,
            'plain_role_after_commit' => $plainAfter,
        ];
    }

    private static function plainRole(Connection $connection, int $userId): ?string
    {
        $role = $connection->table('users')->where('id', $userId)->value('platform_role');

        return is_string($role) ? $role : null;
    }

    /** The MySQL error code the call fails with, or null if it succeeds. */
    private static function errorCodeOf(\Closure $call): ?int
    {
        try {
            $call();
        } catch (QueryException $exception) {
            $code = (int) ($exception->errorInfo[1] ?? 0);

            if ($code !== self::LOCK_NOWAIT && $code !== self::LOCK_WAIT_TIMEOUT) {
                throw $exception;
            }

            return $code;
        }

        return null;
    }
}
