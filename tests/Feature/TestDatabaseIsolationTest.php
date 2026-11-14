<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Tests\Support\Database\LockConnection;

/*
 * ADR-0009 §3: in this process, the default and lock connections are on the
 * same guarded database, and that database is this process's own. Run both
 * sequentially and with --parallel; the expected name differs.
 */

function parallelToken(): ?string
{
    $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');

    return is_scalar($token) && (string) $token !== '' ? (string) $token : null;
}

function expectedTestDatabase(): string
{
    return parallelToken() === null ? 'nexo_testing' : 'nexo_testing_test_'.parallelToken();
}

it('under --parallel, runs on a database named after this process token', function (): void {
    $token = parallelToken();

    if ($token === null) {
        $this->markTestSkipped('Not running under --parallel.');
    }

    expect(ParallelTesting::token())->toEqual($token)
        ->and(LockConnection::serverDatabase(DB::connection()))->toBe('nexo_testing_test_'.$token)
        ->and(LockConnection::serverDatabase(LockConnection::storeConnection(app())))->toBe('nexo_testing_test_'.$token);
});

it('runs on this process\'s own database, on the server and in the lock store', function (): void {
    $expected = expectedTestDatabase();

    expect(LockConnection::serverDatabase(DB::connection()))->toBe($expected)
        ->and(LockConnection::serverDatabase(LockConnection::storeConnection(app())))->toBe($expected)
        ->and(config('database.connections.mysql_locks.database'))->toBe($expected);
});

it('takes locks on mysql_locks, outside the test transaction', function (): void {
    expect(DB::connection()->transactionLevel())->toBeGreaterThan(0)
        ->and(DB::connection('mysql_locks')->transactionLevel())->toBe(0);

    $lock = Cache::lock('isolation-visibility', 30);
    expect($lock->get())->toBeTrue();

    // A brand-new PDO sees the row at once: it is committed, not held in the
    // default connection's open transaction.
    $config = config('database.connections.mysql');
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname=".expectedTestDatabase(),
        $config['username'],
        $config['password'],
    );

    $seen = $pdo->query("SELECT COUNT(*) FROM cache_locks WHERE `key` LIKE '%isolation-visibility'")->fetchColumn();
    expect((int) $seen)->toBe(1);

    $lock->release();
});

it('leaves a lock behind on purpose', function (): void {
    expect(Cache::lock('isolation-leak-probe', 300)->get())->toBeTrue()
        ->and(DB::connection('mysql_locks')->table('cache_locks')->count())->toBe(1);
});

it('starts with no lock left over by the previous test', function (): void {
    expect(DB::connection('mysql_locks')->table('cache_locks')->count())->toBe(0)
        ->and(Cache::lock('isolation-leak-probe', 300)->get())->toBeTrue();
})->depends('it leaves a lock behind on purpose');
