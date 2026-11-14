<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * M6-D-03: in CI the tests connect as `nexo_test`, privileged only on
 * `nexo\_testing%` (.github/mysql/create-test-user.sql). Proven by what the
 * user can and cannot do, not by reading SHOW GRANTS alone.
 *
 * Runs only with NEXO_TEST_PRIVILEGES=1: in CI, and in the local Docker
 * MySQL 8.4 check. Laragon runs as root, which M6-D-03 allows locally.
 */

const PRIVILEGE_PROBE = 'nexo_testing_priv_probe';

beforeEach(function (): void {
    if (getenv('NEXO_TEST_PRIVILEGES') !== '1') {
        $this->markTestSkipped('Needs the dedicated nexo_test user (CI or the local Docker check); set NEXO_TEST_PRIVILEGES=1.');
    }

    DB::statement('DROP DATABASE IF EXISTS `'.PRIVILEGE_PROBE.'`');
});

afterEach(function (): void {
    if (getenv('NEXO_TEST_PRIVILEGES') === '1') {
        DB::statement('DROP DATABASE IF EXISTS `'.PRIVILEGE_PROBE.'`');
    }
});

it('connects as the dedicated test user', function (): void {
    expect(DB::selectOne('SELECT CURRENT_USER() AS u')->u)->toBe('nexo_test@%');
});

it('holds exactly one grant beyond USAGE, on nexo\_testing%', function (): void {
    $grants = array_map(fn (object $row): string => (string) array_values((array) $row)[0], DB::select('SHOW GRANTS'));

    expect($grants)->toBe([
        'GRANT USAGE ON *.* TO `nexo_test`@`%`',
        'GRANT ALL PRIVILEGES ON `nexo\_testing%`.* TO `nexo_test`@`%`',
    ]);
});

it('can create, write, read and drop a database inside the pattern', function (): void {
    DB::statement('CREATE DATABASE `'.PRIVILEGE_PROBE.'`');
    DB::statement('CREATE TABLE `'.PRIVILEGE_PROBE.'`.`t` (id INT PRIMARY KEY)');
    DB::insert('INSERT INTO `'.PRIVILEGE_PROBE.'`.`t` VALUES (7)');

    expect((int) DB::selectOne('SELECT id FROM `'.PRIVILEGE_PROBE.'`.`t`')->id)->toBe(7);

    DB::statement('DROP DATABASE `'.PRIVILEGE_PROBE.'`');

    expect(DB::select('SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?', [PRIVILEGE_PROBE]))->toBe([]);
});

it('is denied outside the pattern', function (string $statement, int $errorCode, ?string $createdName): void {
    try {
        DB::statement($statement);
    } catch (QueryException $denied) {
        expect($denied->errorInfo[1] ?? null)->toBe($errorCode, $denied->getMessage());

        return;
    } finally {
        // Should never be needed; only a broken grant would let this succeed.
        if ($createdName !== null) {
            try {
                DB::statement("DROP DATABASE IF EXISTS `{$createdName}`");
            } catch (QueryException) {
            }
        }
    }

    $this->fail("Expected access denied for: {$statement}");
})->with([
    'create the development database name' => ['CREATE DATABASE `nexo`', 1044, 'nexo'],
    'underscore is literal (nexoXtesting)' => ['CREATE DATABASE `nexoXtesting`', 1044, 'nexoXtesting'],
    'prefix without the underscore' => ['CREATE DATABASE `nexotesting`', 1044, 'nexotesting'],
    'read a database outside the pattern' => ['SELECT * FROM `nexo_ci_canary`.`sentinel`', 1142, null],
    'drop a database outside the pattern' => ['DROP DATABASE `nexo_ci_canary`', 1044, null],
    'read the privilege tables' => ['SELECT * FROM `mysql`.`user`', 1142, null],
    'create a user' => ["CREATE USER 'nexo_escalate'@'%'", 1227, null],
    'widen its own grant' => ["GRANT ALL PRIVILEGES ON *.* TO 'nexo_test'@'%'", 1045, null],
]);

it('cannot see databases outside the pattern', function (): void {
    $names = array_map(fn (object $row): string => (string) array_values((array) $row)[0], DB::select('SHOW DATABASES'));

    expect($names)->toContain('nexo_testing')
        ->and($names)->not->toContain('nexo_ci_canary')
        ->and($names)->not->toContain('mysql');
});
