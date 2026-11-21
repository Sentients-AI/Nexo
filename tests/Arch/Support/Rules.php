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

    /**
     * M5: the synchronous dependency graph has no cycle. M4 holds the code to
     * the registry's edges, so an acyclic registry means acyclic code.
     *
     * @param  array<string, list<string>>  $graph  caller => callees
     */
    public static function acyclic(array $graph): void
    {
        $cycle = self::cycleIn($graph);

        expect($cycle)->toBe([], 'The synchronous dependency graph has a cycle: '.implode(' -> ', $cycle));
    }

    /**
     * Depth-first search for a back edge.
     *
     * @param  array<string, list<string>>  $graph
     * @return list<string> The first cycle found, as a path that returns to its start; empty when there is none.
     */
    private static function cycleIn(array $graph): array
    {
        $done = [];

        $visit = function (string $node, array $path) use (&$visit, &$done, $graph): array {
            $path[] = $node;

            foreach ($graph[$node] ?? [] as $next) {
                if (in_array($next, $path, true)) {
                    return [...array_slice($path, (int) array_search($next, $path, true)), $next];
                }

                if (! isset($done[$next]) && ($cycle = $visit($next, $path)) !== []) {
                    return $cycle;
                }
            }

            $done[$node] = true;

            return [];
        };

        foreach (array_keys($graph) as $node) {
            if (! isset($done[$node]) && ($cycle = $visit($node, [])) !== []) {
                return $cycle;
            }
        }

        return [];
    }
}
