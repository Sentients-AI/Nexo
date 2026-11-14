<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\LockConnection;
use Tests\Support\Database\ProbeDatabases;

/*
 * ADR-0009 §3, refinement 3: the lock connection really reconnects when its
 * database changes, and lock cleanup touches only its own database. Two
 * probe databases stand in for two parallel processes' databases.
 */

beforeEach(function (): void {
    $this->probes = new ProbeDatabases(DB::connection());
    $this->home = LockConnection::serverDatabase(DB::connection());

    foreach ([ProbeDatabases::LOCKS_A, ProbeDatabases::LOCKS_B] as $name) {
        $this->probes->drop($name);
        $this->probes->createWithLockTable($name);
    }
});

afterEach(function (): void {
    // Back to the suite's database before dropping the probes.
    config(['database.connections.mysql.database' => $this->home]);
    DB::purge('mysql');
    LockConnection::pointAt(app(), $this->home);

    foreach ([ProbeDatabases::LOCKS_A, ProbeDatabases::LOCKS_B] as $name) {
        $this->probes->drop($name);
    }
});

function lockRows(string $database): int
{
    return DB::table(DB::raw("`{$database}`.`cache_locks`"))->count();
}

it('reconnects the lock store to a new database, proven on the server', function (): void {
    LockConnection::pointAt(app(), ProbeDatabases::LOCKS_A);

    expect(LockConnection::serverDatabase(LockConnection::storeConnection(app())))->toBe(ProbeDatabases::LOCKS_A)
        ->and(Cache::lock('reconnect', 30)->get())->toBeTrue()
        ->and(lockRows(ProbeDatabases::LOCKS_A))->toBe(1)
        ->and(lockRows(ProbeDatabases::LOCKS_B))->toBe(0);

    LockConnection::pointAt(app(), ProbeDatabases::LOCKS_B);

    expect(LockConnection::serverDatabase(LockConnection::storeConnection(app())))->toBe(ProbeDatabases::LOCKS_B)
        ->and(Cache::lock('reconnect', 30)->get())->toBeTrue()
        ->and(lockRows(ProbeDatabases::LOCKS_B))->toBe(1)
        ->and(lockRows(ProbeDatabases::LOCKS_A))->toBe(1);
});

it('needs the purge: changing configuration alone keeps the old session database (control)', function (): void {
    LockConnection::pointAt(app(), ProbeDatabases::LOCKS_A);
    $connection = LockConnection::storeConnection(app());

    config(['database.connections.mysql_locks.database' => ProbeDatabases::LOCKS_B]);

    expect(LockConnection::serverDatabase($connection))->toBe(ProbeDatabases::LOCKS_A);
});

it('shows why the server is asked: a purged connection object reports a stale name', function (): void {
    LockConnection::pointAt(app(), ProbeDatabases::LOCKS_A);
    $old = LockConnection::storeConnection(app());

    LockConnection::pointAt(app(), ProbeDatabases::LOCKS_B);

    // The old object was reconnected from the new configuration, but its
    // cached name did not change.
    expect($old->getDatabaseName())->toBe(ProbeDatabases::LOCKS_A)
        ->and(LockConnection::serverDatabase($old))->toBe(ProbeDatabases::LOCKS_B);
});

it('cleans only its own database\'s locks', function (): void {
    foreach ([ProbeDatabases::LOCKS_A, ProbeDatabases::LOCKS_B] as $name) {
        DB::table(DB::raw("`{$name}`.`cache_locks`"))->insert(['key' => 'other-'.$name, 'owner' => 'x', 'expiration' => time() + 300]);
    }

    // This "process" runs on LOCKS_B.
    config(['database.connections.mysql.database' => ProbeDatabases::LOCKS_B]);
    DB::purge('mysql');
    LockConnection::pointAt(app(), ProbeDatabases::LOCKS_B);

    expect(LockConnection::clean(app()))->toBe(1)
        ->and(lockRows(ProbeDatabases::LOCKS_B))->toBe(0)
        ->and(lockRows(ProbeDatabases::LOCKS_A))->toBe(1);
});

it('refuses to clean, and deletes nothing, when the lock connection is on another database', function (): void {
    DB::table(DB::raw('`'.ProbeDatabases::LOCKS_A.'`.`cache_locks`'))->insert(['key' => 'keep', 'owner' => 'x', 'expiration' => time() + 300]);

    // Default connection on the suite database, locks on LOCKS_A.
    LockConnection::pointAt(app(), ProbeDatabases::LOCKS_A);

    expect(fn () => LockConnection::clean(app()))->toThrow(RuntimeException::class, 'Refusing to clean locks')
        ->and(lockRows(ProbeDatabases::LOCKS_A))->toBe(1);
});
