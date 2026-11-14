<?php

declare(strict_types=1);

use Tests\Support\Database\GuardInput;
use Tests\Support\Database\TestDatabaseGuard;
use Tests\Support\Database\TestDatabaseGuardViolation;

/*
 * The guard is pure, so every rule is proven here without a database.
 * tests/Platform/GuardAbortsBeforeSchemaTest.php proves that it runs before
 * any schema operation.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mysqlConnection(array $overrides = []): array
{
    return array_merge([
        'driver' => 'mysql',
        'url' => '',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'nexo_testing',
        'username' => 'root',
        'password' => '',
        'unix_socket' => '',
    ], $overrides);
}

/**
 * @param  array<string, array<string, mixed>>|null  $connections
 */
function guardInput(
    ?array $connections = null,
    string $default = 'mysql',
    ?string $env = 'testing',
    bool $cached = false,
): GuardInput {
    return new GuardInput(
        configCached: $cached,
        configCachePath: '/app/bootstrap/cache/config.php',
        appEnv: $env,
        defaultConnection: $default,
        connections: $connections ?? ['mysql' => mysqlConnection(), 'mysql_locks' => mysqlConnection()],
    );
}

/**
 * @return list<string> The rule ids (G1-G5) of the violations.
 */
function ruleIds(GuardInput $input): array
{
    return array_values(array_unique(array_map(
        static fn (string $violation): string => substr($violation, 0, 2),
        TestDatabaseGuard::violations($input),
    )));
}

it('accepts the safe test configuration', function (): void {
    expect(TestDatabaseGuard::violations(guardInput()))->toBe([]);

    TestDatabaseGuard::enforce(guardInput());
});

it('G1 rejects a cached configuration', function (): void {
    expect(ruleIds(guardInput(cached: true)))->toBe(['G1']);
});

it('G2 rejects any environment other than testing', function (?string $env): void {
    expect(ruleIds(guardInput(env: $env)))->toBe(['G2']);
})->with(['local', 'production', 'staging', 'Testing', '', null]);

it('G1 and G2 are evaluated before configuration is loaded', function (): void {
    $input = new GuardInput(configCached: true, configCachePath: 'x', appEnv: 'local');

    expect(ruleIds($input))->toBe(['G1', 'G2']);
});

it('G3 rejects a host outside the allowlist', function (mixed $host): void {
    $input = guardInput(['mysql' => mysqlConnection(['host' => $host])]);

    expect(ruleIds($input))->toBe(['G3']);
})->with([
    'private address' => '10.0.0.5',
    'other loopback address' => '127.0.0.2',
    'hostname' => 'db.example.com',
    'container name' => 'mysql',
    'empty' => '',
    'null' => null,
    'trailing dot' => 'localhost.',
    'array with one bad host' => [['127.0.0.1', '10.0.0.5']],
    'empty array' => [[]],
]);

it('G3 accepts every allowlisted host', function (string $host): void {
    expect(TestDatabaseGuard::violations(guardInput(['mysql' => mysqlConnection(['host' => $host])])))->toBe([]);
})->with(['127.0.0.1', 'localhost', '::1']);

it('G3 checks read and write sub-configurations', function (string $part): void {
    $input = guardInput(['mysql' => mysqlConnection([$part => ['host' => ['10.0.0.9']]])]);

    expect(TestDatabaseGuard::violations($input))
        ->toHaveCount(1)
        ->and(TestDatabaseGuard::violations($input)[0])->toStartWith("G3 connection [mysql.{$part}]");
})->with(['read', 'write']);

it('G4 rejects a URL or a unix socket', function (string $key, string $value): void {
    $input = guardInput(['mysql' => mysqlConnection([$key => $value])]);

    expect(ruleIds($input))->toBe(['G4']);
})->with([
    ['url', 'mysql://root@127.0.0.1/nexo_testing'],
    ['unix_socket', '/var/run/mysqld/mysqld.sock'],
]);

it('G4 applies to read and write sub-configurations', function (): void {
    $input = guardInput(['mysql' => mysqlConnection(['write' => ['url' => 'mysql://x/nexo_testing']])]);

    expect(ruleIds($input))->toBe(['G4']);
});

it('G5 rejects database names outside the pattern', function (mixed $database): void {
    $input = guardInput(['mysql' => mysqlConnection(['database' => $database])]);

    expect(ruleIds($input))->toBe(['G5']);
})->with([
    'nexo',
    'nexo_testing_x',
    'prod_testing',
    'nexo_testing_test_',
    'nexo_testing_test_0',
    'nexo_testing_test_01',
    'nexo_testing_test_1x',
    'NEXO_TESTING',
    'nexo_testing ',
    "nexo_testing\n",
    'xnexo_testing',
    'nexoXtesting',
    '',
    null,
]);

it('G5 accepts the test database and per-process parallel databases', function (string $database): void {
    $input = guardInput(['mysql' => mysqlConnection(['database' => $database])]);

    expect(TestDatabaseGuard::violations($input))->toBe([]);
})->with(['nexo_testing', 'nexo_testing_test_1', 'nexo_testing_test_12', 'nexo_testing_test_9001']);

it('G5 rejects a default connection that is not MySQL', function (): void {
    $input = guardInput([
        'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'mysql' => mysqlConnection(),
    ], default: 'sqlite');

    expect(TestDatabaseGuard::violations($input))->toContain(
        "G5 the default connection [sqlite] uses driver 'sqlite', expected 'mysql'",
    );
});

it('G5 rejects a default connection that is not configured', function (): void {
    expect(ruleIds(guardInput(default: 'missing')))->toBe(['G5']);
});

it('checks every connection, not only the default', function (): void {
    $input = guardInput([
        'mysql' => mysqlConnection(),
        'mysql_locks' => mysqlConnection(['database' => 'nexo']),
    ]);

    expect(TestDatabaseGuard::violations($input))->toBe([
        "G5 connection [mysql_locks] database 'nexo' does not match ".TestDatabaseGuard::DATABASE_PATTERN,
    ]);
});

it('fails closed on a referenced connection with no configuration', function (): void {
    $input = guardInput(['mysql' => mysqlConnection(), 'missing' => []]);

    expect(ruleIds($input))->toBe(['G3', 'G5']);
});

it('reports every violation at once', function (): void {
    $input = guardInput(
        ['mysql' => mysqlConnection(['host' => '10.0.0.5', 'url' => 'mysql://x', 'database' => 'nexo'])],
        env: 'local',
        cached: true,
    );

    expect(ruleIds($input))->toBe(['G1', 'G2', 'G3', 'G4', 'G5']);
});

it('enforce() throws with every violation in the message', function (): void {
    $input = guardInput(['mysql' => mysqlConnection(['database' => 'nexo'])], env: 'local');

    expect(fn () => TestDatabaseGuard::enforce($input))
        ->toThrow(TestDatabaseGuardViolation::class, 'TEST-DB-GUARD G2');

    try {
        TestDatabaseGuard::enforce($input);
    } catch (TestDatabaseGuardViolation $violation) {
        expect($violation->violations)->toHaveCount(2)
            ->and($violation->getMessage())->toContain("TEST-DB-GUARD G5 connection [mysql] database 'nexo'");
    }
});

it('reads G1 and G2 from the process environment', function (): void {
    $base = sys_get_temp_dir().'/nexo-guard-'.bin2hex(random_bytes(4));
    mkdir($base.'/bootstrap/cache', 0777, true);
    $cache = $base.'/cached-config.php';

    $saved = [$_SERVER['APP_CONFIG_CACHE'] ?? null, $_SERVER['APP_ENV'] ?? null];

    try {
        $_SERVER['APP_CONFIG_CACHE'] = $cache;
        $_SERVER['APP_ENV'] = 'testing';
        expect(GuardInput::fromEnvironment($base)->configCached)->toBeFalse();

        touch($cache);
        expect(GuardInput::fromEnvironment($base)->configCached)->toBeTrue();

        unlink($cache);
        unset($_SERVER['APP_CONFIG_CACHE']);
        touch($base.'/bootstrap/cache/config.php');
        expect(GuardInput::fromEnvironment($base)->configCached)->toBeTrue();

        $_SERVER['APP_ENV'] = 'local';
        expect(GuardInput::fromEnvironment($base)->appEnv)->toBe('local');
    } finally {
        foreach (['APP_CONFIG_CACHE' => $saved[0], 'APP_ENV' => $saved[1]] as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        @unlink($base.'/bootstrap/cache/config.php');
        @rmdir($base.'/bootstrap/cache');
        @rmdir($base.'/bootstrap');
        @rmdir($base);
    }
});
