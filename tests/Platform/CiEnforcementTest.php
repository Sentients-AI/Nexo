<?php

declare(strict_types=1);

use Tests\Support\ChildProcess;

/*
 * Proofs that are optional locally are required in CI, and cannot be turned
 * into a green run of skips (M6-D-03, ADR-0009 §3a):
 *
 * - TestUserPrivilegesTest fails in CI unless NEXO_TEST_PRIVILEGES is exactly 1;
 * - Platform proofs fail in CI when TEST_TOKEN is set (--parallel), instead of
 *   skipping.
 *
 * Each case runs a real Platform test file in a child Pest process with an
 * explicit CI, GITHUB_ACTIONS, NEXO_TEST_PRIVILEGES and TEST_TOKEN, so the
 * parent's own CI environment never leaks in. Results come from a JUnit log,
 * not from console output, whose format depends on the terminal. Positive
 * controls show a child given a valid environment passes or skips, so a
 * failure is the rule under test, not the harness.
 *
 * The children never reach a schema operation: they stop in the Platform
 * hook or in the privilege check, after the test database guard has run.
 */

const CI_PRIVILEGES_FILE = 'tests/Platform/TestUserPrivilegesTest.php';

const CI_PRIVILEGES_FILTER = 'connects as the dedicated test user';

const CI_PLATFORM_FILE = 'tests/Platform/ServerBaselineTest.php';

const CI_PLATFORM_FILTER = 'accepts only MySQL 8.4.x as the CI version';

/** Everything that decides CI enforcement, removed unless a case sets it. */
const CI_NEUTRAL = ['CI' => false, 'GITHUB_ACTIONS' => false, 'NEXO_TEST_PRIVILEGES' => false];

/**
 * @param  array<string, string|false>  $overrides
 * @param  list<string>  $arguments
 * @return array{exit: int, tests: int, failures: int, skipped: int, messages: string, output: string}
 */
function ciChildRun(string $file, string $filter, array $overrides, array $arguments = []): array
{
    $junit = sys_get_temp_dir().'/nexo-ci-enforcement-'.bin2hex(random_bytes(4)).'.xml';

    $process = ChildProcess::php(
        ['vendor/bin/pest', $file, '--filter='.$filter, '--colors=never', '--log-junit='.$junit, ...$arguments],
        array_merge(CI_NEUTRAL, $overrides),
    );
    $process->run();

    $xml = is_file($junit) ? simplexml_load_file($junit) : false;
    @unlink($junit);

    $output = $process->getOutput().$process->getErrorOutput();

    if ($xml === false) {
        throw new RuntimeException("The child wrote no JUnit log (exit {$process->getExitCode()}):\n{$output}");
    }

    $suite = $xml->testsuite[0] ?? $xml;
    $messages = implode("\n", array_map('strval', $xml->xpath('//failure | //error') ?: []));

    return [
        'exit' => (int) $process->getExitCode(),
        'tests' => (int) $suite['tests'],
        'failures' => (int) $suite['failures'] + (int) $suite['errors'],
        'skipped' => (int) $suite['skipped'],
        'messages' => $messages,
        'output' => $output,
    ];
}

it('fails the privilege proofs in CI without NEXO_TEST_PRIVILEGES=1', function (array $env): void {
    $run = ciChildRun(CI_PRIVILEGES_FILE, CI_PRIVILEGES_FILTER, $env);

    expect($run['exit'])->not->toBe(0, $run['output'])
        ->and($run['tests'])->toBe(1, $run['output'])
        ->and($run['failures'])->toBe(1, $run['output'])
        ->and($run['skipped'])->toBe(0, $run['output'])
        ->and($run['messages'])->toContain('CI must prove the test user privileges');
})->with([
    'flag missing' => [['CI' => 'true']],
    'flag "true"' => [['CI' => 'true', 'NEXO_TEST_PRIVILEGES' => 'true']],
    'flag "0"' => [['CI' => 'true', 'NEXO_TEST_PRIVILEGES' => '0']],
    'flag empty' => [['CI' => 'true', 'NEXO_TEST_PRIVILEGES' => '']],
    'flag " 1"' => [['CI' => 'true', 'NEXO_TEST_PRIVILEGES' => ' 1']],
    'GITHUB_ACTIONS only' => [['GITHUB_ACTIONS' => 'true']],
    'unexpected CI value' => [['CI' => 'yes']],
]);

it('lets the privilege proofs skip locally without the flag', function (array $env): void {
    $run = ciChildRun(CI_PRIVILEGES_FILE, CI_PRIVILEGES_FILTER, $env);

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($run['tests'])->toBe(1, $run['output'])
        ->and($run['failures'])->toBe(0, $run['output'])
        ->and($run['skipped'])->toBe(1, $run['output']);
})->with([
    'no CI variables' => [[]],
    'CI=false' => [['CI' => 'false']],
]);

it('fails Platform proofs in CI when TEST_TOKEN is set', function (array $env): void {
    $run = ciChildRun(CI_PLATFORM_FILE, CI_PLATFORM_FILTER, ['TEST_TOKEN' => '1', ...$env]);

    expect($run['exit'])->not->toBe(0, $run['output'])
        ->and($run['tests'])->toBeGreaterThan(0, $run['output'])
        ->and($run['failures'])->toBe($run['tests'], $run['output'])
        ->and($run['skipped'])->toBe(0, $run['output'])
        ->and($run['messages'])->toContain('Platform proofs cannot run under --parallel');
})->with([
    'CI=true' => [['CI' => 'true']],
    'GITHUB_ACTIONS only' => [['GITHUB_ACTIONS' => 'true']],
]);

it('lets Platform proofs skip locally when TEST_TOKEN is set', function (): void {
    $run = ciChildRun(CI_PLATFORM_FILE, CI_PLATFORM_FILTER, ['TEST_TOKEN' => '1']);

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($run['tests'])->toBeGreaterThan(0, $run['output'])
        ->and($run['failures'])->toBe(0, $run['output'])
        ->and($run['skipped'])->toBe($run['tests'], $run['output']);
});

it('fails a real --parallel Platform run in CI', function (): void {
    $run = ciChildRun(CI_PLATFORM_FILE, CI_PLATFORM_FILTER, ['CI' => 'true'], ['--parallel', '--processes=1']);

    expect($run['exit'])->not->toBe(0, $run['output'])
        ->and($run['failures'])->toBeGreaterThan(0, $run['output'])
        ->and($run['skipped'])->toBe(0, $run['output'])
        ->and($run['messages'])->toContain('Platform proofs cannot run under --parallel');
});

it('control: the same Platform proofs pass in CI when run sequentially', function (): void {
    $run = ciChildRun(CI_PLATFORM_FILE, CI_PLATFORM_FILTER, ['CI' => 'true']);

    expect($run['exit'])->toBe(0, $run['output'])
        ->and($run['tests'])->toBeGreaterThan(0, $run['output'])
        ->and($run['failures'])->toBe(0, $run['output'])
        ->and($run['skipped'])->toBe(0, $run['output']);
});
