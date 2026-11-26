<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;

/** Negative control for the staff-exclusivity proof: both checks run before either write. */
final class UnserializedMembershipAssignment implements Scenario
{
    public function prepare(Application $app, int $worker, array $arguments): void
    {
        DB::connection()->getPdo();
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        $userId = (int) $arguments['user'];
        if ($worker === 0) {
            if (DB::table('vendor_members')->where('user_id', $userId)->where('active_marker', 1)->exists()) {
                throw new LogicException('The control starts without an active membership.');
            }
            $context->signal('staff-checked');
            $context->waitFor('member-checked');
            DB::table('users')->where('id', $userId)->update(['platform_role' => 'admin']);
        } else {
            if (DB::table('users')->where('id', $userId)->value('platform_role') !== null) {
                throw new LogicException('The control starts without a staff role.');
            }
            $context->signal('member-checked');
            $context->waitFor('staff-checked');
            DB::table('vendor_members')->insert(['vendor_id' => $arguments['vendor'], 'user_id' => $userId, 'role' => 'owner']); // @phpstan-ignore nexo.membershipWrite (Deliberate unaudited race control.)
        }

        return ['outcome' => 'success'];
    }
}
