<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Writes one row of audit_log, inside the caller's transaction (ADR-0023
 * Decisions 1 and 4; INV-19). It is the only code that writes audit_log, and
 * it only ever inserts (dependency-rules.md L10).
 *
 * It uses the default connection and requires a transaction open on that
 * same connection: a transaction on any other connection does not count. It
 * never begins, commits, rolls back or retries a transaction, and never
 * catches a failed insert: the exception reaches the caller, whose
 * transaction then decides. The record therefore exists if and only if the
 * caller's transaction finally commits.
 *
 * The recorder alone does not make an operation audited. That takes an
 * orchestrator that changes the subject and calls record() in one
 * transaction, as ADR-0023 Decisions 5 and 6 describe; none is wired yet.
 *
 * Snapshots: $before and $after are stored as given, as JSON objects. Pass a
 * deliberate, minimal set of the fields the action changes, read from the
 * database under the orchestrator's lock, never a whole serialised model:
 * the log is append-only, so whatever is recorded, secrets and personal data
 * included, cannot be taken out again.
 */
final readonly class AuditRecorder
{
    public function __construct(private ConnectionResolverInterface $connections) {}

    /**
     * @param  string  $action  What happened, for example vendor.approved.
     * @param  string  $subjectType  The kind of record changed, for example vendor.
     * @param  int  $subjectId  The changed record's id.
     * @param  array<string, mixed>|null  $before  The audited fields before the change; null when there was no subject yet.
     * @param  array<string, mixed>|null  $after  The audited fields after the change; null when the subject no longer exists.
     *
     * @throws AuditOutsideTransaction when the default connection has no open transaction; nothing is written
     * @throws InvalidArgumentException when the action, subject type or subject id is malformed; nothing is written
     * @throws \JsonException when a snapshot cannot be encoded as JSON; nothing is written
     * @throws QueryException when the insert fails; the caller's transaction decides what happens next
     */
    public function record(Actor $actor, string $action, string $subjectType, int $subjectId, ?array $before, ?array $after): void
    {
        // The default connection, resolved once: the transaction check and the
        // insert run on the same connection object.
        $name = $this->connections->getDefaultConnection();
        $connection = $this->connections->connection($name);

        if ($connection->transactionLevel() === 0) {
            throw AuditOutsideTransaction::onConnection($name);
        }

        self::assertName($action, 'action');
        self::assertName($subjectType, 'subject type');

        if ($subjectId < 1) {
            throw new InvalidArgumentException("An audit subject id must be a positive id, got {$subjectId}.");
        }

        $connection->table('audit_log')->insert([
            'occurred_at' => Date::now()->format('Y-m-d H:i:s.u'),
            'actor_type' => $actor->type->value,
            'actor_user_id' => $actor->userId,
            'impersonator_user_id' => $actor->impersonatorUserId,
            'actor_label' => $actor->label,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => self::json($before),
            'after' => self::json($after),
            'ip' => $actor->ip,
        ]);
    }

    private static function assertName(string $value, string $name): void
    {
        if (trim($value) === '' || trim($value) !== $value) {
            throw new InvalidArgumentException("An audit {$name} must be non-empty, without surrounding whitespace, got \"{$value}\".");
        }
    }

    /**
     * Always a JSON object, so an empty snapshot is {} rather than [].
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    private static function json(?array $snapshot): ?string
    {
        return $snapshot === null
            ? null
            : json_encode((object) $snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
