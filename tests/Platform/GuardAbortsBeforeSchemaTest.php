<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ChildProcess;
use Tests\Support\Database\ProbeDatabases;

/*
 * ADR-0009 §3a, M6-D-02 criterion 3: each guard rule aborts the run before
 * any schema is created, dropped or migrated.
 *
 * Each case runs tests/Probes/MigratingProbeTest.php (RefreshDatabase) in a
 * child process whose target is an empty probe database. The positive
 * control shows the same probe, given a safe environment, does migrate that
 * database, so "still empty" means the guard stopped the run.
 *
 * Safety: the child can only ever target the probe databases. A broken guard
 * could touch nothing but a throwaway database inside nexo\_testing%.
 */

beforeEach(function (): void {
    $this->probes = new ProbeDatabases(DB::connection());

    foreach (ProbeDatabases::ALL as $name) {
        $this->probes->drop($name);
    }

    $this->probes->create(ProbeDatabases::VALID);
    $this->probes->create(ProbeDatabases::INVALID);
});

afterEach(function (): void {
    foreach (ProbeDatabases::ALL as $name) {
        $this->probes->drop($name);
    }
});

function output(Process $process): string
{
    return $process->getOutput().$process->getErrorOutput();
}

it('positive control: with a safe environment the probe migrates its database', function (): void {
    $process = ChildProcess::probe(['DB_DATABASE' => ProbeDatabases::VALID]);

    expect($process->getExitCode())->toBe(0, output($process))
        ->and(output($process))->not->toContain('TEST-DB-GUARD')
        ->and($this->probes->tableCount(ProbeDatabases::VALID))->toBeGreaterThan(0);
});

it('aborts before any schema operation', function (string $rule, Closure $environment, string $target): void {
    $process = ChildProcess::probe($environment());

    expect($process->getExitCode())->not->toBe(0)
        ->and(output($process))->toContain('TEST-DB-GUARD '.$rule)
        ->and(output($process))->not->toContain('migrates its database')
        ->and($this->probes->tableCount($target))->toBe(0);
})->with([
    'G1 cached configuration' => ['G1', fn (): array => [
        'DB_DATABASE' => ProbeDatabases::VALID,
        'APP_CONFIG_CACHE' => cachedConfigurationFor(ProbeDatabases::VALID),
    ], ProbeDatabases::VALID],
    'G2 APP_ENV=local' => ['G2', fn (): array => [
        'DB_DATABASE' => ProbeDatabases::VALID,
        'APP_ENV' => 'local',
    ], ProbeDatabases::VALID],
    'G3 host outside the allowlist' => ['G3', fn (): array => [
        'DB_DATABASE' => ProbeDatabases::VALID,
        'DB_HOST' => '127.0.0.2',
    ], ProbeDatabases::VALID],
    'G4 URL' => ['G4', fn (): array => [
        'DB_DATABASE' => ProbeDatabases::VALID,
        'DB_URL' => 'mysql://127.0.0.1/'.ProbeDatabases::VALID,
    ], ProbeDatabases::VALID],
    'G4 unix socket' => ['G4', fn (): array => [
        'DB_DATABASE' => ProbeDatabases::VALID,
        'DB_SOCKET' => '/tmp/nexo-no-such-mysqld.sock',
    ], ProbeDatabases::VALID],
    'G5 database name' => ['G5', fn (): array => [
        'DB_DATABASE' => ProbeDatabases::INVALID,
    ], ProbeDatabases::INVALID],
]);

it('aborts a --parallel --recreate-databases run before the runner drops anything', function (): void {
    // What the runner's per-process callback would drop for this base name.
    $this->probes->create(ProbeDatabases::INVALID_PARALLEL);
    DB::statement('CREATE TABLE `'.ProbeDatabases::INVALID_PARALLEL.'`.`sentinel` (id int PRIMARY KEY)');

    $process = ChildProcess::probe(
        ['DB_DATABASE' => ProbeDatabases::INVALID],
        ['--parallel', '--processes=1', '--recreate-databases'],
    );

    expect($process->getExitCode())->not->toBe(0)
        ->and(output($process))->toContain('TEST-DB-GUARD G5')
        ->and($this->probes->exists(ProbeDatabases::INVALID_PARALLEL))->toBeTrue()
        ->and($this->probes->tableCount(ProbeDatabases::INVALID_PARALLEL))->toBe(1)
        ->and($this->probes->tableCount(ProbeDatabases::INVALID))->toBe(0);
});

it('aborts a --parallel run whose base name would derive an unguarded database', function (): void {
    // nexo_testing_test_9001 is valid, but --parallel would create
    // nexo_testing_test_9001_test_1 before any later check.
    $process = ChildProcess::probe(['DB_DATABASE' => ProbeDatabases::VALID], ['--parallel', '--processes=1']);

    $created = DB::selectOne(
        'SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name = ?',
        [ProbeDatabases::VALID.'_test_1'],
    )->n;

    expect($process->getExitCode())->not->toBe(0)
        ->and(output($process))->toContain('under --parallel must be')
        ->and((int) $created)->toBe(0)
        ->and($this->probes->tableCount(ProbeDatabases::VALID))->toBe(0);
});

/**
 * A cached-configuration file (as `config:cache` writes it) pointing at the
 * probe database, so a guard that ignored G1 would migrate the probe.
 */
function cachedConfigurationFor(string $database): string
{
    $config = config()->all();
    $config['app']['env'] = 'testing';
    $config['database']['connections']['mysql']['database'] = $database;
    $config['database']['connections']['mysql_locks']['database'] = $database;

    $path = sys_get_temp_dir().'/nexo-guard-cached-config-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($path, '<?php return '.var_export($config, true).';'.PHP_EOL);

    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}
