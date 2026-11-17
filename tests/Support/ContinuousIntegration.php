<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Whether the tests run in CI. Proofs that are optional locally but required
 * in CI fail, rather than skip, when this is true.
 *
 * GitHub Actions sets CI=true and GITHUB_ACTIONS=true. Detection fails
 * closed: CI counts as set unless it is absent, empty, "0" or "false", so an
 * unexpected value enforces the CI rules instead of silently relaxing them.
 */
final class ContinuousIntegration
{
    public static function detected(): bool
    {
        return self::detectedIn([
            'CI' => self::env('CI'),
            'GITHUB_ACTIONS' => self::env('GITHUB_ACTIONS'),
        ]);
    }

    /**
     * @param  array{CI?: ?string, GITHUB_ACTIONS?: ?string}  $env
     */
    public static function detectedIn(array $env): bool
    {
        if (($env['GITHUB_ACTIONS'] ?? null) === 'true') {
            return true;
        }

        $ci = $env['CI'] ?? null;

        return $ci !== null && ! in_array(strtolower(trim($ci)), ['', '0', 'false'], true);
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) ? $value : null;
    }
}
