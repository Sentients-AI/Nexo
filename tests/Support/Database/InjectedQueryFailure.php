<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Deterministic failure injection for retry tests (ADR-0023 testing
 * implications 7 and 8): the next $times statements on the connection that
 * start with $prefix fail before they reach MySQL, with the QueryException
 * MySQL's error would raise. Laravel recognises DEADLOCK and
 * LOCK_WAIT_TIMEOUT as concurrency errors by their message.
 *
 * It simulates the error, not the server's reaction to it: after a real
 * deadlock MySQL has already rolled the whole transaction back. Real
 * contention is proven separately with worker processes.
 */
final class InjectedQueryFailure
{
    public const DEADLOCK = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction';

    public const LOCK_WAIT_TIMEOUT = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction';

    /** How many statements have failed so far. */
    public int $thrown = 0;

    private function __construct(private readonly string $prefix, private readonly string $message, private readonly int $times) {}

    public static function on(string $prefix, string $message, int $times = 1, ?Connection $connection = null): self
    {
        $failure = new self($prefix, $message, $times);

        ($connection ?? DB::connection())->beforeExecuting(function (string $query, array $bindings, Connection $connection) use ($failure): void {
            if ($failure->thrown >= $failure->times || ! str_starts_with($query, $failure->prefix)) {
                return;
            }

            $failure->thrown++;

            throw new QueryException((string) $connection->getName(), $query, $bindings, new PDOException($failure->message));
        });

        return $failure;
    }
}
