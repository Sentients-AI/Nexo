<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Explicitly named, throwaway databases used by the platform proofs.
 *
 * Only the names listed here can be created, inspected or dropped, so a
 * mistake in a test can never reach the development database `nexo` or any
 * other schema. Every name is covered by the CI user's `nexo\_testing%` grant.
 */
final class ProbeDatabases
{
    /** Valid under G5: the guard-probe target and its positive control. */
    public const string VALID = 'nexo_testing_test_9001';

    /** Invalid under G5, but inside the grant: the G5 subprocess target. */
    public const string INVALID = 'nexo_testing_x';

    /** What Laravel's parallel runner would create or drop for INVALID. */
    public const string INVALID_PARALLEL = 'nexo_testing_x_test_1';

    /** Two process databases for lock-isolation proofs. */
    public const string LOCKS_A = 'nexo_testing_test_9101';

    public const string LOCKS_B = 'nexo_testing_test_9102';

    /** @var list<string> */
    public const array ALL = [self::VALID, self::INVALID, self::INVALID_PARALLEL, self::LOCKS_A, self::LOCKS_B];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function create(string $name): void
    {
        $this->connection->statement('CREATE DATABASE '.self::quoted($name)
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    /**
     * A probe database with a `cache_locks` table shaped like the one in
     * database/migrations/0001_01_01_000001_create_cache_table.php.
     */
    public function createWithLockTable(string $name): void
    {
        $this->create($name);

        $this->connection->statement('CREATE TABLE '.self::quoted($name).'.`cache_locks` ('
            .'`key` varchar(255) NOT NULL PRIMARY KEY, '
            .'`owner` varchar(255) NOT NULL, '
            .'`expiration` bigint NOT NULL, '
            .'INDEX `cache_locks_expiration_index` (`expiration`))');
    }

    public function drop(string $name): void
    {
        $this->connection->statement('DROP DATABASE IF EXISTS '.self::quoted($name));
    }

    public function exists(string $name): bool
    {
        return $this->connection->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name = ?',
            [self::allowed($name)],
        )->n > 0;
    }

    public function tableCount(string $name): int
    {
        return (int) $this->connection->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ?',
            [self::allowed($name)],
        )->n;
    }

    private static function quoted(string $name): string
    {
        return '`'.self::allowed($name).'`';
    }

    private static function allowed(string $name): string
    {
        if (! in_array($name, self::ALL, true)) {
            throw new InvalidArgumentException("'{$name}' is not a probe database.");
        }

        return $name;
    }
}
