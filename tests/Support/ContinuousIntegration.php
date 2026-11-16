<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Whether the tests run in CI. GitHub Actions, like most CI providers, sets
 * CI=true. Proofs that are optional locally but required in CI fail, rather
 * than skip, when this is true.
 */
final class ContinuousIntegration
{
    public static function detected(): bool
    {
        $value = getenv('CI');

        return $value === 'true' || $value === '1';
    }
}
