<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Support\ChildProcess;
use Tests\Support\Redis\NegativeControls;
use Tests\Support\Redis\RedisGate;

/*
 * ADR-0022 §5, §7: a wrong host, identity, password or namespace stops the
 * run before any Redis command.
 *
 * Each case runs tests/Probes/RedisProbeTest.php in a child process. The
 * probe writes a marker key; the positive control shows it does, given a safe
 * environment. A case passes only if the child fails, names the rule, and
 * left no marker. Where the unsafe setting moves the marker elsewhere inside
 * nexo_testing: (another prefix), that location is checked too.
 */

/**
 * @param  array<string, string|false>  $overrides
 */
function redisProbe(string $token, array $overrides): Process
{
    $process = ChildProcess::php(
        ['vendor/bin/pest', 'tests/Probes/RedisProbeTest.php', '--colors=never'],
        array_merge(['NEXO_REDIS_PROBE_TOKEN' => $token], $overrides),
    );
    $process->run();

    return $process;
}

function redisProbeOutput(Process $process): string
{
    return $process->getOutput().$process->getErrorOutput();
}

/**
 * @return string|false The marker under the given full key, read without the prefix.
 */
function redisMarker(string $fullKey): string|false
{
    $value = Redis::connection()->client()->rawCommand('GET', $fullKey);

    return is_string($value) ? $value : false;
}

beforeEach(function (): void {
    $this->token = bin2hex(random_bytes(8));
});

afterEach(function (): void {
    if (! RedisGate::passed()) {
        return;
    }

    Redis::connection()->client()->rawCommand('UNLINK', 'nexo_testing:probe:marker', 'nexo_testing:other:probe:marker');
});

it('positive control: with a safe environment the probe writes its marker', function (): void {
    $process = redisProbe($this->token, []);

    expect($process->getExitCode())->toBe(0, redisProbeOutput($process))
        ->and(redisProbeOutput($process))->not->toContain('TEST-DB-GUARD')
        ->and(redisMarker('nexo_testing:probe:marker'))->toBe($this->token);
});

it('fails closed before any command', function (string $rule, array $overrides, ?string $elsewhere): void {
    $process = redisProbe($this->token, $overrides);
    $output = redisProbeOutput($process);

    expect($process->getExitCode())->not->toBe(0)
        ->and($output)->toContain('TEST-DB-GUARD '.$rule)
        ->and(redisMarker('nexo_testing:probe:marker'))->toBeFalse();

    if ($elsewhere !== null) {
        expect(redisMarker($elsewhere))->toBeFalse();
    }
})->with(NegativeControls::CASES);
