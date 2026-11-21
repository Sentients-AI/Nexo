<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

use Closure;
use RuntimeException;

/**
 * Bounded polling for server-side expiry (TTLs, reserved-job timeouts).
 *
 * Tests assert on what the server reports (PTTL, presence), never on how
 * long a sleep took. The deadline only bounds a broken run; a passing run
 * returns as soon as the condition holds.
 */
final class Eventually
{
    /**
     * @template T
     *
     * @param  Closure(): T  $probe  Returns a value; the wait ends when $until accepts it.
     * @param  Closure(T): bool  $until
     * @return T
     */
    public static function value(Closure $probe, Closure $until, float $timeoutSeconds = 10.0, string $what = 'the condition'): mixed
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $value = $probe();

            if ($until($value)) {
                return $value;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException("Timed out after {$timeoutSeconds}s waiting for {$what}.");
            }

            usleep(20_000);
        }
    }
}
