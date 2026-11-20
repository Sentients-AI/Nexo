<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Composer\PackagePolicy;

/*
 * ADR-0022 §10: composer.lock must not install predis/predis,
 * adilazhari/laravel-trace or adilazhari/laravel-idempotency.
 *
 * Every case writes its own temporary lockfile; the repository's
 * composer.lock is only ever read.
 */

/**
 * @param  list<array<string, mixed>>  $packages
 * @param  list<array<string, mixed>>  $dev
 */
function lockJson(array $packages = [], array $dev = []): string
{
    return json_encode([
        '_readme' => ['fixture'],
        'content-hash' => str_repeat('0', 32),
        'packages' => $packages,
        'packages-dev' => $dev,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function lockPackage(string $name, array $extra = []): array
{
    return array_merge(['name' => $name, 'version' => 'v1.0.0', 'type' => 'library'], $extra);
}

function policyFixture(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'nexo-lock-');
    file_put_contents($path, $contents);
    $GLOBALS['nexoPolicyFixtures'][] = $path;

    return $path;
}

afterEach(function (): void {
    foreach ($GLOBALS['nexoPolicyFixtures'] ?? [] as $path) {
        @unlink($path);
    }

    $GLOBALS['nexoPolicyFixtures'] = [];
});

it('passes a clean lockfile', function (): void {
    expect(PackagePolicy::violations(lockJson(
        [lockPackage('laravel/framework'), lockPackage('symfony/console')],
        [lockPackage('pestphp/pest')],
    )))->toBe([]);
});

it('fails each prohibited package', function (string $name): void {
    $violations = PackagePolicy::violations(lockJson([lockPackage('laravel/framework'), lockPackage($name)]));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith($name.' is installed (composer.lock "packages")');
})->with(array_keys(PackagePolicy::PROHIBITED));

it('fails a prohibited package installed only for development', function (string $name): void {
    expect(PackagePolicy::violations(lockJson([lockPackage('laravel/framework')], [lockPackage($name)]))[0])
        ->toStartWith($name.' is installed (composer.lock "packages-dev")');
})->with(array_keys(PackagePolicy::PROHIBITED));

it('matches names ignoring case, as Composer does', function (string $name): void {
    expect(PackagePolicy::violations(lockJson([lockPackage($name)])))->toHaveCount(1);
})->with([
    'upper case' => 'PREDIS/PREDIS',
    'mixed case vendor' => 'AdilAzhari/laravel-trace',
    'mixed case package' => 'adilazhari/Laravel-Idempotency',
]);

it('fails a prohibited package pulled in transitively', function (): void {
    // composer.lock lists every installed package, direct or not, as its own
    // record; here only some/wrapper requires the prohibited one.
    $violations = PackagePolicy::violations(lockJson([
        lockPackage('some/wrapper', ['require' => ['adilazhari/laravel-trace' => '^0.2']]),
        lockPackage('adilazhari/laravel-trace'),
    ]));

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('composer why adilazhari/laravel-trace');
});

it('allows a package that only mentions a prohibited one without installing it', function (): void {
    // laravel/framework lists predis in require-dev and suggest, as in the
    // real lockfile.
    expect(PackagePolicy::violations(lockJson([
        lockPackage('laravel/framework', [
            'require-dev' => ['predis/predis' => '^2.3 || ^3.0'],
            'suggest' => ['predis/predis' => 'Required to use the predis connector (^2.3 || ^3.0).'],
            'conflict' => ['adilazhari/laravel-idempotency' => '*'],
        ]),
    ])))->toBe([]);
});

it('allows unrelated packages with similar names', function (string $name): void {
    expect(PackagePolicy::violations(lockJson([lockPackage($name)])))->toBe([]);
})->with([
    'another predis vendor' => 'predis/predis-async',
    'a fork' => 'acme/predis',
    'a prefix' => 'predis/predis-extra',
    'the same vendor' => 'adilazhari/laravel-trace-ui',
    'another vendor' => 'other/laravel-trace',
    'a suffix match' => 'xadilazhari/laravel-idempotency',
]);

it('fails closed on a malformed lockfile', function (string $contents, string $violation): void {
    expect(PackagePolicy::violations($contents))->toContain($violation);
})->with([
    'invalid JSON' => ['{"packages": [', 'composer.lock is not valid JSON (Syntax error)'],
    'empty' => ['', 'composer.lock is not valid JSON (Syntax error)'],
    'a JSON list' => ['[]', 'composer.lock is not a JSON object'],
    'a JSON string' => ['"lock"', 'composer.lock is not a JSON object'],
    'no packages section' => ['{"packages-dev": []}', 'composer.lock has no "packages" section'],
    'no packages-dev section' => ['{"packages": []}', 'composer.lock has no "packages-dev" section'],
    'packages is an object' => ['{"packages": {"a": {"name": "predis/predis"}}, "packages-dev": []}', 'composer.lock "packages" is not a list of packages'],
    'packages-dev is a string' => ['{"packages": [], "packages-dev": "none"}', 'composer.lock "packages-dev" is not a list of packages'],
]);

it('fails closed on a malformed package record', function (mixed $record): void {
    expect(PackagePolicy::violations(json_encode(['packages' => [lockPackage('laravel/framework'), $record], 'packages-dev' => []], JSON_THROW_ON_ERROR)))
        ->toBe(['composer.lock "packages" entry #1 has no valid package name']);
})->with([
    'no name' => [['version' => '1.0.0']],
    'a null name' => [['name' => null]],
    'a numeric name' => [['name' => 42]],
    'no vendor' => [['name' => 'predis']],
    'whitespace' => [['name' => 'predis/ predis']],
    'a string record' => ['predis/predis'],
    'a null record' => [null],
]);

it('fails closed on a missing lockfile', function (): void {
    expect(PackagePolicy::violationsInFile(sys_get_temp_dir().'/nexo-no-such-composer-'.bin2hex(random_bytes(4)).'.lock')[0])
        ->toStartWith('composer.lock not found at');
});

it('reports every prohibited package, not just the first', function (): void {
    expect(PackagePolicy::violations(lockJson(
        [lockPackage('predis/predis'), lockPackage('adilazhari/laravel-trace')],
        [lockPackage('adilazhari/laravel-idempotency')],
    )))->toHaveCount(3);
});

it('passes the repository lockfile without changing it', function (): void {
    $lock = dirname(__DIR__, 2).'/composer.lock';
    $before = hash_file('sha256', $lock);

    expect(PackagePolicy::violationsInFile($lock))->toBe([])
        ->and(hash_file('sha256', $lock))->toBe($before);
});

it('runs as a CLI check that exits non-zero on a violation and prints no lockfile contents', function (): void {
    $script = dirname(__DIR__, 2).'/tests/bin/check-package-policy.php';

    $clean = new Process([PHP_BINARY, $script, policyFixture(lockJson([lockPackage('laravel/framework')]))]);
    $clean->run();

    $bad = new Process([PHP_BINARY, $script, policyFixture(lockJson([lockPackage('laravel/framework', ['description' => 'SECRET-DESCRIPTION'])], [lockPackage('Predis/Predis')]))]);
    $bad->run();

    $missing = new Process([PHP_BINARY, $script, sys_get_temp_dir().'/nexo-no-such-'.bin2hex(random_bytes(4)).'.lock']);
    $missing->run();

    expect($clean->getExitCode())->toBe(0, $clean->getErrorOutput())
        ->and($bad->getExitCode())->toBe(1)
        ->and($bad->getErrorOutput())->toContain('PACKAGE-POLICY Predis/Predis is installed (composer.lock "packages-dev")')
        ->and($bad->getOutput().$bad->getErrorOutput())->not->toContain('SECRET-DESCRIPTION')
        ->and($missing->getExitCode())->toBe(1);
});
