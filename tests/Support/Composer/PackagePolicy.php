<?php

declare(strict_types=1);

namespace Tests\Support\Composer;

use JsonException;

/**
 * ADR-0022 §10: packages that must not be installed.
 *
 * Reads composer.lock, the record of what Composer installs, including every
 * transitive dependency, and fails if a prohibited package is among the
 * installed records in `packages` or `packages-dev`. Names match exactly,
 * ignoring case, as Composer treats them. A package that only mentions a
 * prohibited one (in its `require-dev`, `suggest`, `conflict` and so on) is
 * not installing it and passes.
 *
 * Fails closed: a missing or unreadable file, invalid JSON, a missing or
 * malformed section, or a record without a valid name is a violation, never
 * a pass.
 *
 * Dependency-free on purpose, so CI can run it before `composer install`
 * (tests/bin/check-package-policy.php).
 */
final class PackagePolicy
{
    /**
     * Adoption is deferred: ADR-0022 §10 (this list) and ADR-0010 (package
     * adoption not decided). Changing it needs an owner decision.
     *
     * @var array<string, string> Package => why it is prohibited.
     */
    public const array PROHIBITED = [
        'predis/predis' => 'the Redis client is phpredis (ADR-0022 §3); predis needs a new decision',
        'adilazhari/laravel-trace' => 'first-party package adoption is deferred (ADR-0010, ADR-0022 §10)',
        'adilazhari/laravel-idempotency' => 'first-party package adoption is deferred (ADR-0010, ADR-0022 §10)',
    ];

    private const array SECTIONS = ['packages', 'packages-dev'];

    /**
     * @return list<string>
     */
    public static function violationsInFile(string $path): array
    {
        if (! is_file($path)) {
            return ['composer.lock not found at '.$path.'; run composer update --lock, never skip this check'];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return ['composer.lock at '.$path.' could not be read'];
        }

        return self::violations($contents);
    }

    /**
     * @return list<string>
     */
    public static function violations(string $lockJson): array
    {
        try {
            $lock = json_decode($lockJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['composer.lock is not valid JSON ('.$e->getMessage().')'];
        }

        if (! is_array($lock) || array_is_list($lock)) {
            return ['composer.lock is not a JSON object'];
        }

        $violations = [];

        foreach (self::SECTIONS as $section) {
            if (! array_key_exists($section, $lock)) {
                $violations[] = "composer.lock has no \"{$section}\" section";

                continue;
            }

            if (! is_array($lock[$section]) || ! array_is_list($lock[$section])) {
                $violations[] = "composer.lock \"{$section}\" is not a list of packages";

                continue;
            }

            foreach ($lock[$section] as $index => $package) {
                $name = is_array($package) ? ($package['name'] ?? null) : null;

                if (! is_string($name) || preg_match('#^[^/\s]+/[^/\s]+$#', $name) !== 1) {
                    $violations[] = "composer.lock \"{$section}\" entry #{$index} has no valid package name";

                    continue;
                }

                $normalised = strtolower($name);

                if (array_key_exists($normalised, self::PROHIBITED)) {
                    $violations[] = "{$name} is installed (composer.lock \"{$section}\"): ".self::PROHIBITED[$normalised]
                        .'. Remove it (composer remove, or find what requires it with composer why '.$normalised.').';
                }
            }
        }

        return $violations;
    }
}
