<?php

declare(strict_types=1);

/*
| A concurrency worker, started by Tests\Concurrency\Harness\Concurrently.
|
| Usage: php tests/Concurrency/bin/worker.php <scenario-class> <run-dir> <worker> <base64-json-arguments>
|
| Prints exactly one line "NEXO-WORKER-RESULT {json}" on stdout.
| Exit codes: 0 ok, 3 guard violation, 4 barrier timeout, 5 scenario error.
*/

use Tests\Concurrency\Harness\Concurrently;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;
use Tests\CreatesApplication;
use Tests\Support\Database\TestDatabaseGuardViolation;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/**
 * @param  array<string, mixed>  $report
 */
function report(array $report, int $exitCode): never
{
    fwrite(STDOUT, PHP_EOL.Concurrently::RESULT_MARKER.json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);

    exit($exitCode);
}

/** @var list<string> $arguments */
$arguments = array_values(array_map('strval', (array) ($_SERVER['argv'] ?? [])));
[, $scenarioClass, $runDirectory, $worker, $encodedArguments] = $arguments + [null, '', '', '0', ''];
$worker = (int) $worker;

try {
    /** @var array<string, mixed> $arguments */
    $arguments = json_decode((string) base64_decode((string) $encodedArguments, true), true, 512, JSON_THROW_ON_ERROR);

    if (! is_subclass_of($scenarioClass, Scenario::class)) {
        throw new InvalidArgumentException('Not a scenario: '.var_export($scenarioClass, true));
    }

    $app = (new class
    {
        use CreatesApplication;
    })->createApplication();
} catch (TestDatabaseGuardViolation $violation) {
    report(['status' => 'guard_violation', 'class' => $violation::class, 'message' => $violation->getMessage()], 3);
} catch (Throwable $error) {
    report(['status' => 'boot_error', 'class' => $error::class, 'message' => $error->getMessage()], 5);
}

$context = new WorkerContext((string) $runDirectory, $worker);
/** @var Scenario $scenario */
$scenario = new $scenarioClass;

try {
    $scenario->prepare($app, $worker, $arguments);
} catch (Throwable $error) {
    report(['status' => 'exception', 'class' => $error::class, 'message' => $error->getMessage()], 5);
}

$readyAt = microtime(true);
file_put_contents($runDirectory.'/ready-'.$worker, (string) $readyAt);

try {
    $context->waitForFile($runDirectory.'/go', 10.0, 'the start barrier');
} catch (Throwable $error) {
    report(['status' => 'barrier_timeout', 'class' => $error::class, 'message' => $error->getMessage()], 4);
}

$releasedAt = microtime(true);

try {
    $data = $scenario->run($app, $worker, $arguments, $context);
} catch (Throwable $error) {
    report(['status' => 'exception', 'class' => $error::class, 'message' => $error->getMessage()], 5);
}

report([
    'status' => 'ok',
    'worker' => $worker,
    'ready_at' => $readyAt,
    'released_at' => $releasedAt,
    'finished_at' => microtime(true),
    'data' => $data,
], 0);
