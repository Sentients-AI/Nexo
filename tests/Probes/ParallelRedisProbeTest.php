<?php

declare(strict_types=1);

/*
 * Not part of any test suite. tests/Redis/ParallelPrefixTest.php runs this
 * file in child processes that present themselves as parallel workers
 * (LARAVEL_PARALLEL_TESTING=1, TEST_TOKEN=<N>). It stands for an ordinary
 * Feature test using Redis under --parallel: it writes the same logical key
 * as every other worker and reports the prefix it ended up with.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Redis as PhpRedis;
use Tests\TestCase;

uses(TestCase::class);

it('writes the shared logical key in its own namespace', function (): void {
    $token = (string) getenv('TEST_TOKEN');

    Redis::connection()->set('parallel:probe', 'worker-'.$token, 'EX', 60);
    Cache::store('redis')->put('parallel:cache-probe', 'worker-'.$token, 60);

    // Laravel's parallel runner also makes cache.prefix per worker, so the
    // cache key's full name is reported rather than guessed.
    fwrite(STDOUT, 'NEXO-PREFIX '.Redis::connection()->client()->getOption(PhpRedis::OPT_PREFIX).PHP_EOL);
    fwrite(STDOUT, 'NEXO-CACHE-PREFIX '.Cache::store('redis')->getPrefix().PHP_EOL);

    expect(true)->toBeTrue();
});
