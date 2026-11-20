<?php

declare(strict_types=1);

/*
 * Not part of any test suite. tests/Redis/UngatedResolutionTest.php runs this
 * file in a child process. It stands for an ordinary Feature test that uses
 * Redis without RedisGate: it resolves a connection and writes a marker key,
 * so a missing marker proves the configuration was refused before any
 * command.
 */

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class);

it('writes its marker without RedisGate', function (): void {
    $token = getenv('NEXO_REDIS_PROBE_TOKEN');

    expect($token)->toBeString()->not->toBe('');

    Redis::connection()->set('probe:ungated', $token, 'EX', 60);
});
