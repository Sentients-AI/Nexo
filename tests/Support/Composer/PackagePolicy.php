<?php

declare(strict_types=1);

namespace Tests\Support\Composer;

use JsonException;

/**
 * ADR-0022 §10: packages that must not be installed.
 *
 * Reads composer.lock, the record of what Composer installs, including every
 * transitive dependency, and fails if a prohibited package is among the
 * installed records in `packages` or `packages-dev`, or if an installed
 * record substitutes for one through its `replace` or `provide` map. Names
 * match exactly, ignoring case, as Composer treats them. A package that only
 * refers to a prohibited one (in `require`, `require-dev`, `suggest`,
 * `conflict` and so on) is not installing it and passes.
 *
 * The substitution rule is conservative: it rejects any declared stand-in,
 * without proving the stand-in contains the prohibited code (or that a
 * package declaring nothing does not).
 *
 * Fails closed: a missing or unreadable file, invalid JSON, a missing or
 * malformed section, a record without a valid name, or a `replace` or
 * `provide` that is not a name-to-constraint map is a violation, never a
 * pass.
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

                array_push($violations, ...self::substitutionViolations($name, $section, (array) $package));
            }
        }

        return $violations;
    }

    /**
     * A package that `replace`s or `provide`s a prohibited one stands in for
     * it, so Composer would treat the prohibited package as installed.
     *
     * @param  array<mixed>  $package
     * @return list<string>
     */
    private static function substitutionViolations(string $name, string $section, array $package): array
    {
        $violations = [];

        foreach (['replace', 'provide'] as $link) {
            if (! array_key_exists($link, $package)) {
                continue;
            }

            $targets = $package[$link];

            // Composer writes an empty map as [] in a lockfile.
            if ($targets === []) {
                continue;
            }

            if (! is_array($targets) || array_is_list($targets)) {
                $violations[] = "{$name} (composer.lock \"{$section}\") has a \"{$link}\" that is not a map of package names";

                continue;
            }

            foreach (array_keys($targets) as $target) {
                $normalised = strtolower((string) $target);

                if (array_key_exists($normalised, self::PROHIBITED)) {
                    $violations[] = "{$name} {$link}s {$target} (composer.lock \"{$section}\"), standing in for a prohibited package: "
                        .self::PROHIBITED[$normalised].'. Remove '.$name.' or get an owner decision.';
                }
            }
        }

        return $violations;
    }
}
