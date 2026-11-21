<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ContinuousIntegration;

/*
 * ADR-0009 §2 and §4: tests run on the engine semantics the code relies on,
 * on both connections tests use (`mysql`, and `mysql_locks` for locks).
 * InnoDB, REPEATABLE READ, strict mode, utf8mb4 / utf8mb4_0900_ai_ci, and
 * MySQL 8.4 in CI (8.0 is tolerated locally).
 *
 * The settings are read from the server session, and the behaviour they
 * promise is proven: a snapshot read, a strict-mode rejection, the engine and
 * collation of a table built the way migrations build them. Each check has a
 * control showing it fails when the setting is wrong.
 */

const BASELINE_PROBE_TABLE = 'platform_baseline_probe';

const BASELINE_COLLATION = 'utf8mb4_0900_ai_ci';

beforeEach(function (): void {
    Schema::connection('mysql')->dropIfExists(BASELINE_PROBE_TABLE);
});

afterEach(function (): void {
    // A control may have changed the session; start the next test on a new one.
    DB::purge('mysql');

    Schema::connection('mysql')->dropIfExists(BASELINE_PROBE_TABLE);
});

/**
 * @return list<string> One entry per session setting that differs from ADR-0009 §2.
 */
function baselineSessionViolations(ConnectionInterface $connection): array
{
    $session = $connection->selectOne(
        'SELECT @@SESSION.transaction_isolation AS isolation, @@SESSION.sql_mode AS sql_mode,'
        .' @@SESSION.default_storage_engine AS engine, @@SESSION.character_set_connection AS charset,'
        .' @@SESSION.collation_connection AS collation, DATABASE() AS db',
    );

    $schema = $connection->selectOne(
        'SELECT default_character_set_name AS charset, default_collation_name AS collation'
        .' FROM information_schema.schemata WHERE schema_name = ?',
        [$session->db],
    );

    $violations = [];

    if ($session->isolation !== 'REPEATABLE-READ') {
        $violations[] = 'transaction_isolation is '.$session->isolation.', expected REPEATABLE-READ';
    }

    if (! in_array('STRICT_TRANS_TABLES', explode(',', (string) $session->sql_mode), true)) {
        $violations[] = "sql_mode '".$session->sql_mode."' lacks STRICT_TRANS_TABLES";
    }

    if ($session->engine !== 'InnoDB') {
        $violations[] = 'default_storage_engine is '.$session->engine.', expected InnoDB';
    }

    if ($session->charset !== 'utf8mb4' || $session->collation !== BASELINE_COLLATION) {
        $violations[] = 'connection uses '.$session->charset.'/'.$session->collation.', expected utf8mb4/'.BASELINE_COLLATION;
    }

    if ($schema === null || $schema->charset !== 'utf8mb4' || $schema->collation !== BASELINE_COLLATION) {
        $violations[] = 'database '.$session->db.' defaults to '
            .($schema === null ? 'nothing' : $schema->charset.'/'.$schema->collation).', expected utf8mb4/'.BASELINE_COLLATION;
    }

    return $violations;
}

/**
 * @return list<string> Empty when the server version is MySQL 8.4.x.
 */
function baselineVersionViolations(string $version): array
{
    return preg_match('/^8\.4\.\d+(\D|$)/', $version) === 1
        ? []
        : ['MySQL '.$version.', expected 8.4.x in CI (ADR-0009 §4)'];
}

function baselineCreateProbeTable(): void
{
    // Through the schema builder, with no engine or collation given, exactly
    // as migrations create tables.
    Schema::connection('mysql')->create(BASELINE_PROBE_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('code', 3);
    });
}

function baselineProbeRows(ConnectionInterface $connection): int
{
    return (int) $connection->selectOne('SELECT COUNT(*) AS n FROM `'.BASELINE_PROBE_TABLE.'`')->n;
}

it('matches the ADR-0009 session baseline', function (string $connection): void {
    expect(baselineSessionViolations(DB::connection($connection)))->toBe([]);
})->with(['mysql', 'mysql_locks']);

it('runs on MySQL 8.4 in CI', function (): void {
    if (! ContinuousIntegration::detected()) {
        $this->markTestSkipped('Local MySQL 8.0 is tolerated (ADR-0009); CI must run 8.4.');
    }

    foreach (['mysql', 'mysql_locks'] as $connection) {
        $version = (string) DB::connection($connection)->selectOne('SELECT VERSION() AS v')->v;

        expect(baselineVersionViolations($version))->toBe([], $connection);
    }
});

it('creates migration tables as InnoDB with the baseline collation', function (): void {
    baselineCreateProbeTable();

    $table = DB::selectOne(
        'SELECT engine AS engine, table_collation AS collation FROM information_schema.tables'
        .' WHERE table_schema = DATABASE() AND table_name = ?',
        [BASELINE_PROBE_TABLE],
    );

    expect($table->engine)->toBe('InnoDB')
        ->and($table->collation)->toBe(BASELINE_COLLATION);
});

it('keeps a REPEATABLE READ snapshot while the lock connection commits', function (): void {
    baselineCreateProbeTable();
    $reader = DB::connection('mysql');

    $reader->transaction(function () use ($reader): void {
        expect(baselineProbeRows($reader))->toBe(0);

        DB::connection('mysql_locks')->insert('INSERT INTO `'.BASELINE_PROBE_TABLE.'` (code) VALUES (?)', ['abc']);

        expect(baselineProbeRows($reader))->toBe(0);
    });

    expect(baselineProbeRows($reader))->toBe(1);
});

it('rejects an over-long value in strict mode', function (): void {
    baselineCreateProbeTable();

    try {
        DB::insert('INSERT INTO `'.BASELINE_PROBE_TABLE.'` (code) VALUES (?)', ['abcdef']);
    } catch (QueryException $rejected) {
        expect($rejected->errorInfo[1] ?? null)->toBe(1406, $rejected->getMessage())
            ->and(baselineProbeRows(DB::connection()))->toBe(0);

        return;
    }

    $this->fail('Strict mode accepted a value longer than the column.');
});

it('accepts only MySQL 8.4.x as the CI version', function (string $version, bool $accepted): void {
    expect(baselineVersionViolations($version) === [])->toBe($accepted);
})->with([
    ['8.4.13', true],
    ['8.4.0', true],
    ['8.4.13-log', true],
    ['8.0.30', false],
    ['8.40.1', false],
    ['9.4.0', false],
    ['5.8.4.1', false],
    ['10.11.6-MariaDB', false],
]);

it('control: a READ COMMITTED session is reported and sees the other commit', function (): void {
    baselineCreateProbeTable();
    $reader = DB::connection('mysql');
    $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

    expect(baselineSessionViolations($reader))->toBe(['transaction_isolation is READ-COMMITTED, expected REPEATABLE-READ']);

    $reader->transaction(function () use ($reader): void {
        expect(baselineProbeRows($reader))->toBe(0);

        DB::connection('mysql_locks')->insert('INSERT INTO `'.BASELINE_PROBE_TABLE.'` (code) VALUES (?)', ['abc']);

        expect(baselineProbeRows($reader))->toBe(1);
    });
});

it('control: a non-strict session is reported and truncates silently', function (): void {
    baselineCreateProbeTable();
    $connection = DB::connection('mysql');
    $connection->statement("SET SESSION sql_mode = ''");

    expect(baselineSessionViolations($connection))->toBe(["sql_mode '' lacks STRICT_TRANS_TABLES"]);

    $connection->insert('INSERT INTO `'.BASELINE_PROBE_TABLE.'` (code) VALUES (?)', ['abcdef']);

    expect($connection->selectOne('SELECT code FROM `'.BASELINE_PROBE_TABLE.'`')->code)->toBe('abc');
});

it('control: another engine or collation is reported', function (string $statement, string $violation): void {
    $connection = DB::connection('mysql');
    $connection->statement($statement);

    expect(baselineSessionViolations($connection))->toBe([$violation]);
})->with([
    'MyISAM' => ['SET SESSION default_storage_engine = MyISAM', 'default_storage_engine is MyISAM, expected InnoDB'],
    'unicode_ci' => ['SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', 'connection uses utf8mb4/utf8mb4_unicode_ci, expected utf8mb4/utf8mb4_0900_ai_ci'],
]);
