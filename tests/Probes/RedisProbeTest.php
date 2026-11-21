<?php

declare(strict_types=1);

/*
 * Not part of any test suite. tests/Redis/GuardFailsClosedTest.php runs this
 * file in a child process with a deliberately unsafe Redis environment. The
 * probe writes a marker key, so a missing marker afterwards proves the guard
 * stopped the run before any command.
 */

use Illuminate\Support\Facades\Redis;
use Tests\Support\Redis\RedisGate;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    RedisGate::enter();
});

it('writes its marker', function (): void {
    $token = getenv('NEXO_REDIS_PROBE_TOKEN');

    expect($token)->toBeString()->not->toBe('');

    Redis::connection()->set('probe:marker', $token, 'EX', 60);
});
