<?php

namespace Tests\Arch\Support;

/**
 * The dependency rules (docs/architecture/dependency-rules.md), each written
 * once against the namespaces it checks.
 *
 * The suite runs every rule twice: against the application, where it must
 * pass, and against its fixture under Tests\Arch\Fixtures, where it must fail
 * (ADR-0010 §7). Each expectation is a temporary, so Pest verifies it when its
 * statement ends, inside the rule.
 */
final class Rules
{
    /** Namespaced application code. `config/` is not namespaced and is the one place env() belongs. */
    public const APPLICATION = ['App', 'Database\Factories', 'Database\Seeders'];

    /** @var list<string> */
    public const DEBUG_HELPERS = ['dd', 'dump', 'ray', 'var_dump', 'print_r'];

    /**
     * L6: env() is read only in config/.
     *
     * @param  list<string>  $namespaces
     */
    public static function envOnlyInConfig(array $namespaces): void
    {
        foreach ($namespaces as $namespace) {
            expect($namespace)->not->toUse('env');
        }
    }

    /**
     * L7: no debug helpers in committed code.
     *
     * @param  list<string>  $namespaces
     */
    public static function noDebugHelpers(array $namespaces): void
    {
        foreach ($namespaces as $namespace) {
            expect($namespace)->not->toUse(self::DEBUG_HELPERS);
        }
    }
}
