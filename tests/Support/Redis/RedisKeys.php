<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use Illuminate\Support\Facades\Redis;

/**
 * Keys a test created, so clean-up deletes exactly those (ADR-0022 §6).
 * FLUSHDB, KEYS and SCAN are denied by the ACL, so there is no other way.
 *
 * Keys are recorded as Laravel sees them, without the connection prefix;
 * clean-up goes through the same connection, which adds it back.
 */
final class RedisKeys
{
    /** @var array<string, array<string, true>> Connection name => keys. */
    private static array $keys = [];

    public static function track(string $key, string $connection = 'default'): string
    {
        self::$keys[$connection][$key] = true;

        return $key;
    }

    public static function cleanUp(): void
    {
        $keys = self::$keys;
        self::$keys = [];

        foreach ($keys as $connection => $names) {
            Redis::connection($connection)->command('unlink', array_keys($names));
        }
    }
}
