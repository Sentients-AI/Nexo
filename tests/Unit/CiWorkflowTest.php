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
