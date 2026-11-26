<?php

declare(strict_types=1);

/*
 * #38: `composer test` (and so `composer check`) runs the suites in separate
 * PHP processes, grouped as CI groups them. The Arch suite's PHPStan
 * RuleTestCase boots Larastan, which bootstraps a Laravel application and
 * leaves its error and exception handlers installed; a runtime test that
 * followed in the same process removed them on teardown and was reported
 * risky ("removed exception handlers other than its own"). These checks keep
 * every suite in phpunit.xml covered exactly once and Arch in a process of
 * its own.
 */

/** @return list<string> The `php artisan test` commands of `composer test`, in order. */
function composerTestRuns(): array
{
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    return array_values(array_filter(
        $composer['scripts']['test'] ?? [],
        static fn (string $command): bool => str_starts_with($command, '@php artisan test'),
    ));
}

/** @return list<list<string>> The suites each run selects. */
function composerTestRunSuites(): array
{
    return array_map(static function (string $command): array {
        expect(preg_match('/^@php artisan test --testsuite=([A-Za-z,]+)$/', $command, $match))
            ->toBe(1, "\"{$command}\" must select its suites with --testsuite and nothing else");

        return explode(',', $match[1]);
    }, composerTestRuns());
}

it('runs every phpunit.xml suite exactly once', function (): void {
    $phpunit = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
    $declared = array_map(static fn (SimpleXMLElement $suite): string => (string) $suite['name'], iterator_to_array($phpunit->testsuites->testsuite, false));
    $run = array_merge(...composerTestRunSuites());

    sort($declared);
    sort($run);

    expect($run)->toBe($declared);
});

it('runs the Arch suite in a process of its own', function (): void {
    expect(composerTestRunSuites())->toContain(['Arch']);
});
