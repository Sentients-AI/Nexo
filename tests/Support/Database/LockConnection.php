<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The `database` cache store's lock connection (`mysql_locks`) in tests.
 *
 * Every check reads the session's current database from the server
 * (SELECT DATABASE()), never from configuration or from
 * Connection::getDatabaseName(). After a purge, an existing Connection object
 * keeps its old database name but is silently reconnected from the current
 * configuration, so only the server answer is reliable.
 */
final class LockConnection
{
    public const string NAME = 'mysql_locks';

    /**
     * Point `mysql_locks` at $database and prove the lock store now really
     * uses it. Laravel's parallel switch renames only the default connection.
     */
    public static function pointAt(Application $app, string $database): void
    {
        $app->make('config')->set('database.connections.'.self::NAME.'.database', $database);

        $app->make('db')->purge(self::NAME);
        // The store holds the old Connection object; resolve a new store.
        $app->make('cache')->forgetDriver('database');

        $actual = self::serverDatabase(self::storeConnection($app));

        if ($actual !== $database) {
            throw new RuntimeException("The lock connection uses database '{$actual}', expected '{$database}'.");
        }
    }

    /**
     * Empty `cache_locks`, but only after proving the lock connection is on
     * the same server database as the default connection and that the name
     * is a guarded test database. Fails closed: nothing is deleted otherwise.
     *
     * @return int The number of lock rows deleted.
     */
    public static function clean(Application $app): int
    {
        $locks = self::storeConnection($app);
        $lockDatabase = self::serverDatabase($locks);
        $defaultDatabase = self::serverDatabase($app->make('db')->connection());

        if ($lockDatabase !== $defaultDatabase) {
            throw new RuntimeException(
                "Refusing to clean locks: the lock connection is on '{$lockDatabase}' but the default connection is on '{$defaultDatabase}'."
            );
        }

        if (preg_match(TestDatabaseGuard::DATABASE_PATTERN, $lockDatabase) !== 1) {
            throw new RuntimeException("Refusing to clean locks in '{$lockDatabase}': not a test database.");
        }

        return $locks->table(self::table($app))->delete();
    }

    /**
     * The connection the `database` cache store actually takes locks on.
     */
    public static function storeConnection(Application $app): Connection
    {
        $store = $app->make('cache')->store('database')->getStore();

        if (! $store instanceof DatabaseStore) {
            throw new RuntimeException('The `database` cache store is not a DatabaseStore.');
        }

        $connection = $store->getLockConnection() ?? $store->getConnection();

        if (! $connection instanceof Connection) {
            throw new RuntimeException('The lock connection is not a database Connection.');
        }

        if ($connection->getName() !== self::NAME) {
            throw new RuntimeException("Locks use connection '{$connection->getName()}', expected '".self::NAME."'.");
        }

        return $connection;
    }

    public static function serverDatabase(ConnectionInterface $connection): string
    {
        $row = $connection->selectOne('SELECT DATABASE() AS name');
        $name = is_object($row) ? ($row->name ?? null) : null;

        if (! is_string($name)) {
            throw new RuntimeException('The connection has no current database.');
        }

        return $name;
    }

    public static function table(Application $app): string
    {
        $table = $app->make('config')->get('cache.stores.database.lock_table');

        return is_string($table) && $table !== '' ? $table : 'cache_locks';
    }
}
