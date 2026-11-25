<?php

declare(strict_types=1);

namespace Tests\Support\Audit;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Deterministic failure injection for the audit insert (ADR-0023, testing
 * implication 1): the next insert into audit_log on the connection fails
 * once, before it reaches MySQL, with the QueryException a refused insert
 * raises. Later queries, and later tests on the same connection, run
 * normally.
 */
final class AuditInsertFailure
{
    public const MESSAGE = 'Injected audit insert failure.';

    /** The exception thrown, once the insert was attempted. */
    public ?QueryException $thrown = null;

    private bool $armed = true;

    private function __construct() {}

    public static function injectOnce(?Connection $connection = null): self
    {
        $failure = new self;
        $connection ??= DB::connection();

        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection) use ($failure): void {
            if (! $failure->armed || ! str_starts_with($query, 'insert into `audit_log`')) {
                return;
            }

            $failure->armed = false;

            throw $failure->thrown = new QueryException((string) $connection->getName(), $query, $bindings, new PDOException(self::MESSAGE));
        });

        return $failure;
    }
}
