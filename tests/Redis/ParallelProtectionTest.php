<?php

declare(strict_types=1);

use Tests\Support\ChildProcess;

/*
 * ADR-0022 §11: Redis tests share one key namespace, so they never run under
 * --parallel. A parallel worker (TEST_TOKEN set) skips them locally and fails
 * them in CI, the same rule the Platform proofs follow.
 *
 * Each case runs one real Redis test in a child Pest process with an explicit
 * CI and TEST_TOKEN. Results come from a JUnit log, not console output. The
 * positive control shows the same test passes sequentially, so the skip and
 * the failure are the rule under test.
 */

/**
 * @param  array<string, string|false>  $overrides
 * @return array{tests: int, failures: int, skipped: int, messages: string}
 */
function redisChildRun(array $overrides): array
{
    $junit = sys_get_temp_dir().'/nexo-redis-parallel-'.bin2hex(random_bytes(4)).'.xml';

    $process = ChildProcess::php(
        ['vendor/bin/pest', 'tests/Redis/NamespaceIsolationTest.php', '--filter=positive control', '--colors=never', '--log-junit='.$junit],
        array_merge(['CI' => false, 'GITHUB_ACTIONS' => false], $overrides),
    );
    $process->run();

    $xml = is_file($junit) ? simplexml_load_file($junit) : false;
    @unlink($junit);

    if ($xml === false) {
        throw new RuntimeException("The child wrote no JUnit log (exit {$process->getExitCode()}):\n".$process->getOutput().$process->getErrorOutput());
    }

    $suite = $xml->testsuite;

    return [
        'tests' => (int) $suite['tests'],
        'failures' => (int) $suite['failures'] + (int) $suite['errors'],
        'skipped' => (int) $suite['skipped'],
        'messages' => implode("\n", array_map(strval(...), $xml->xpath('//failure|//error|//skipped') ?: [])),
    ];
}

it('positive control: the test passes when run sequentially', function (): void {
    $run = redisChildRun([]);

    expect($run)->toMatchArray(['tests' => 1, 'failures' => 0, 'skipped' => 0]);
});

it('skips Redis tests in a local parallel worker', function (): void {
    $run = redisChildRun(['TEST_TOKEN' => '2']);

    expect($run)->toMatchArray(['tests' => 1, 'failures' => 0, 'skipped' => 1]);
});

it('fails Redis tests in a CI parallel worker instead of skipping them', function (array $ci): void {
    $run = redisChildRun(['TEST_TOKEN' => '2', ...$ci]);

    expect($run)->toMatchArray(['tests' => 1, 'failures' => 1, 'skipped' => 0])
        ->and($run['messages'])->toContain('Redis tests cannot run under --parallel');
})->with([
    'GITHUB_ACTIONS' => [['GITHUB_ACTIONS' => 'true']],
    'CI' => [['CI' => 'true']],
]);
