<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * A deterministic job for the Redis queue contract tests. It records what
 * happened to it under `jobs:<token>:*` in the test namespace and nothing
 * else.
 */
final class RecordingJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $token, public bool $fail = false) {}

    public static function handledKey(string $token): string
    {
        return 'jobs:'.$token.':handled';
    }

    public static function failedKey(string $token): string
    {
        return 'jobs:'.$token.':failed';
    }

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('RecordingJob failed on purpose');
        }

        Redis::connection()->setex(self::handledKey($this->token), 300, (string) $this->attempts());
    }

    public function failed(?Throwable $exception): void
    {
        Redis::connection()->setex(self::failedKey($this->token), 300, (string) $exception?->getMessage());
    }
}
