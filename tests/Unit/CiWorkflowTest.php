<?php

declare(strict_types=1);

/*
 * ADR-0022 §11: RedisGate turns a skipped Redis proof into a CI failure, but
 * only when the suite runs. These checks keep CI running it: the required
 * job must start Valkey, set NEXO_TEST_REDIS=1, run the Redis suite
 * sequentially and unconditionally, and wrap every test stage in the
 * sentinel check.
 *
 * Text checks on .github/workflows/ci.yml: the repository has no YAML parser,
 * and these assertions need only the step names, commands and keys.
 */

/**
 * @return array<string, string> Step name => the step's text, in order, for
 *                               the required "PHP 8.5 / Laravel 13" job.
 */
function ciTestJobSteps(): array
{
    $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

    expect(preg_match('/^  test:\n(.*?)(?=^  [a-z][a-z0-9_-]*:\n|\z)/ms', $workflow, $job))->toBe(1, 'ci.yml has no "test" job');

    $parts = preg_split('/^      - name: (.+)$/m', $job[1], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $steps = [];

    for ($i = 1; $i + 1 < count($parts); $i += 2) {
        $steps[trim($parts[$i])] = $parts[$i + 1];
    }

    return $steps;
}

function ciTestJob(): string
{
    $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
    preg_match('/^  test:\n(.*?)(?=^  [a-z][a-z0-9_-]*:\n|\z)/ms', $workflow, $job);

    return $job[1] ?? '';
}

it('keeps the required job name', function (): void {
    expect(ciTestJob())->toContain("    name: PHP 8.5 / Laravel 13\n");
});

it('makes the Redis suite mandatory for the whole job', function (): void {
    expect(ciTestJob())->toMatch("/^    env:\n(?:      .+\n)*?      NEXO_TEST_REDIS: '1'\n/m");
});

it('starts Valkey and seeds the sentinel before any test stage', function (): void {
    $names = array_keys(ciTestJobSteps());
    $firstTest = min(array_keys(array_filter(
        array_values(ciTestJobSteps()),
        static fn (string $step): bool => str_contains($step, 'php artisan test'),
    )));

    expect($names)->toContain('Start Valkey', 'Seed the Valkey sentinel')
        ->and(ciTestJobSteps()['Start Valkey'])->toContain('run: bash .github/valkey/start.sh')
        ->and(array_search('Start Valkey', $names, true))->toBeLessThan(array_search('Seed the Valkey sentinel', $names, true))
        ->and(array_search('Seed the Valkey sentinel', $names, true))->toBeLessThan($firstTest);
});

it('starts the disposable Valkey for the reconnect test before any test stage, on its own port', function (): void {
    $steps = ciTestJobSteps();
    $names = array_keys($steps);
    $name = 'Start the disposable Valkey for the reconnect test';
    $firstTest = min(array_keys(array_filter(array_values($steps), static fn (string $step): bool => str_contains($step, 'php artisan test'))));

    expect(ciTestJob())->toMatch("/^    env:\n(?:      .+\n)*?      NEXO_TEST_DISPOSABLE_REDIS_PORT: '6391'\n/m")
        ->and($steps[$name] ?? '')->toContain('run: bash .github/valkey/start-disposable.sh')
        ->and($steps[$name] ?? '')->not->toContain('continue-on-error')
        ->and(array_search($name, $names, true))->toBeLessThan($firstTest)
        // The test Valkey keeps 6379; the disposable one must never share it.
        ->and((string) file_get_contents(dirname(__DIR__, 2).'/.github/valkey/start.sh'))->toContain('-p 127.0.0.1:6379:6379');
});

it('gives the disposable Valkey an unpredictable per-run marker, handed to the tests through GITHUB_ENV', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/.github/valkey/start-disposable.sh');

    expect($script)->toContain('MARKER="nexo-disposable-$(od -An -N16 -tx1 /dev/urandom')
        ->and($script)->toContain('echo "NEXO_TEST_DISPOSABLE_REDIS_MARKER=$MARKER" >> "$GITHUB_ENV"')
        // Refuses a server that already holds data before the marker is set.
        ->and($script)->toContain("cli INFO keyspace | grep '^db'")
        ->and($script)->toContain('set -euo pipefail');
});

it('runs the Redis suite sequentially, unconditionally and without continue-on-error', function (): void {
    $steps = ciTestJobSteps();

    expect($steps)->toHaveKey('Run Redis tests')
        ->and($steps['Run Redis tests'])->toContain('run: php artisan test --testsuite=Redis')
        ->and($steps['Run Redis tests'])->not->toContain('--parallel')
        ->and($steps['Run Redis tests'])->not->toContain('if:')
        ->and($steps['Run Redis tests'])->not->toContain('continue-on-error');

    foreach ($steps as $name => $step) {
        if (str_contains($step, '--parallel')) {
            expect($step)->not->toContain('Redis', "Step [{$name}] runs the Redis suite in parallel");
        }
    }
});

it('verifies the sentinel after every test stage, even when one failed', function (): void {
    $steps = ciTestJobSteps();
    $names = array_keys($steps);
    $verify = array_search('Verify the Valkey sentinel is unchanged', $names, true);

    expect($verify)->not->toBeFalse()
        ->and($steps['Verify the Valkey sentinel is unchanged'])
        ->toContain("if: \${{ !cancelled() && steps.sentinel.outcome == 'success' }}");

    foreach ($steps as $name => $step) {
        if (str_contains($step, 'php artisan test')) {
            expect(array_search($name, $names, true))->toBeLessThan($verify, "Step [{$name}] runs after the sentinel check");
        }
    }
});

it('enforces the package policy before dependencies are installed and before any test', function (): void {
    $steps = ciTestJobSteps();
    $names = array_keys($steps);
    $name = 'Check the package policy';
    $policy = array_search($name, $names, true);

    expect($policy)->not->toBeFalse()
        ->and($steps[$name])->toContain('run: php tests/bin/check-package-policy.php composer.lock')
        ->and($steps[$name])->not->toContain('if:')
        ->and($steps[$name])->not->toContain('continue-on-error')
        ->and($policy)->toBeLessThan(array_search('Install dependencies', $names, true));

    foreach ($steps as $step => $text) {
        if (str_contains($text, 'php artisan test')) {
            expect($policy)->toBeLessThan(array_search($step, $names, true), "Step [{$step}] runs before the package policy");
        }
    }
});

it('proves from the server that the negative-control children send no command', function (): void {
    $steps = ciTestJobSteps();
    $names = array_keys($steps);
    $name = 'Prove the negative-control children send no Redis command';
    $step = $steps[$name] ?? '';

    $before = strpos($step, '.github/valkey/admin.sh INFO commandstats > "$RUNNER_TEMP/negative-before.txt"');
    $run = strpos($step, 'php tests/Redis/bin/run-negative-controls.php');
    $after = strpos($step, '.github/valkey/admin.sh INFO commandstats > "$RUNNER_TEMP/negative-after.txt"');
    $verify = strpos($step, 'php tests/Redis/bin/verify-negative-controls.php "$RUNNER_TEMP/negative-before.txt" "$RUNNER_TEMP/negative-after.txt"');

    expect($names)->toContain($name)
        ->and([$before, $run, $after, $verify])->not->toContain(false)
        ->and($before < $run && $run < $after && $after < $verify)->toBeTrue('snapshot, run, snapshot, verify')
        ->and($step)->not->toContain('continue-on-error')
        ->and($step)->not->toContain('if:')
        ->and(array_search($name, $names, true))->toBeGreaterThan(array_search('Run Redis tests', $names, true));
});

it('checks the live ACL and command statistics after every test stage', function (): void {
    $steps = ciTestJobSteps();
    $names = array_keys($steps);
    $name = 'Verify the live Valkey ACL and command statistics';
    $policy = array_search($name, $names, true);

    expect($policy)->not->toBeFalse()
        ->and($steps[$name])->toContain("if: \${{ !cancelled() && steps.sentinel.outcome == 'success' }}")
        ->and($steps[$name])->toContain('.github/valkey/admin.sh ACL LIST > "$RUNNER_TEMP/valkey-acl.txt"')
        ->and($steps[$name])->toContain('.github/valkey/admin.sh INFO commandstats > "$RUNNER_TEMP/valkey-commandstats.txt"')
        ->and($steps[$name])->toContain('php tests/Redis/bin/verify-valkey-policy.php "$RUNNER_TEMP/valkey-acl.txt" "$RUNNER_TEMP/valkey-commandstats.txt"')
        ->and($steps[$name])->not->toContain('continue-on-error');

    foreach ($steps as $step => $text) {
        if (str_contains($text, 'php artisan test')) {
            expect(array_search($step, $names, true))->toBeLessThan($policy, "Step [{$step}] runs after the policy check");
        }
    }
});

it('runs the architecture suite unconditionally, before the other test stages (ADR-0010 §7)', function (): void {
    $steps = ciTestJobSteps();
    $names = array_keys($steps);
    $firstTest = min(array_keys(array_filter(array_values($steps), static fn (string $step): bool => str_contains($step, 'php artisan test'))));

    expect($steps)->toHaveKey('Run architecture tests')
        ->and($steps['Run architecture tests'])->toContain('run: php artisan test --testsuite=Arch')
        ->and($steps['Run architecture tests'])->not->toContain('if:')
        ->and($steps['Run architecture tests'])->not->toContain('continue-on-error')
        ->and(array_search('Run architecture tests', $names, true))->toBe($firstTest);
});
