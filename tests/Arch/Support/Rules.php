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

    /** The provider SDK of L5 (ADR-0015). */
    public const PROVIDER_SDK = 'Stripe';

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

    /** L1: the domain layer uses no HTTP, UI, admin or provider code. */
    public static function domainIsFrameworkFree(string $modules): void
    {
        foreach (Registry::MODULES as $module) {
            expect("{$modules}\\{$module}\\Domain")->not->toUse([
                'Illuminate\Http', 'Illuminate\Support\Facades\Request', 'Inertia', 'Filament', 'Livewire', 'Stripe',
            ]);
        }
    }

    /**
     * L2: a use case depends on neither of its module's delivery layers (Http,
     * Filament) nor on Inertia or Filament.
     */
    public static function applicationIgnoresDelivery(string $modules): void
    {
        foreach (Registry::MODULES as $module) {
            expect("{$modules}\\{$module}\\Application")->not->toUse([
                "{$modules}\\{$module}\\Http", "{$modules}\\{$module}\\Filament", 'Inertia', 'Filament',
            ]);
        }
    }

    /**
     * L3, the part an arch test can see: controllers do not use the DB facade.
     * The global DB alias has no autoload root, so Pest cannot see it; it falls
     * to the deferred method-call part (a PHPStan rule, dependency-rules.md §6).
     */
    public static function controllersAvoidTheDatabase(string $modules): void
    {
        foreach (Registry::MODULES as $module) {
            expect("{$modules}\\{$module}\\Http\\Controllers")->not->toUse('Illuminate\Support\Facades\DB');
        }
    }

    /**
     * L5: provider SDKs are used only in Payments\Infrastructure.
     *
     * Pest matches a dependency only under a Composer PSR-4 root, so the rule
     * detects Stripe once the SDK is installed, before any code can use it. The
     * fixture proves the rule with a stand-in SDK that is autoloadable now.
     *
     * @param  list<string>  $namespaces
     */
    public static function providerSdkOnlyInPayments(array $namespaces, string $modules, string $sdk = self::PROVIDER_SDK): void
    {
        foreach ($namespaces as $namespace) {
            expect($namespace)->not->toUse($sdk)->ignoring("{$modules}\\Payments\\Infrastructure");
        }
    }

    /**
     * C1: modules and the shared kernel never import the composition root
     * (App\Providers, kernel-level App\Http) or App\Models. There is no
     * exception: Identity's App\Models\User exception ended when User moved to
     * Identity\Domain\User (ADR-0010 §2b; #33).
     */
    public static function compositionRootIsNotImported(string $app): void
    {
        $root = ["{$app}\\Providers", "{$app}\\Http", "{$app}\\Models"];

        foreach (Registry::MODULES as $module) {
            expect("{$app}\\Modules\\{$module}")->not->toUse($root);
        }

        expect("{$app}\\Shared")->not->toUse($root);
    }

    /**
     * Composition root (ADR-0010 §2a, owner decision D4): App\Providers and
     * kernel-level App\Http import module Contracts, and module Http, Filament
     * or Infrastructure classes to register them, never a module's Domain or
     * Application. A module's service provider lives in its Infrastructure.
     */
    public static function compositionRootOnlyRegisters(string $app): void
    {
        $internals = [];

        foreach (Registry::MODULES as $module) {
            $internals[] = "{$app}\\Modules\\{$module}\\Domain";
            $internals[] = "{$app}\\Modules\\{$module}\\Application";
        }

        expect("{$app}\\Providers")->not->toUse($internals);
        expect("{$app}\\Http")->not->toUse($internals);
    }

    /**
     * C2: the legacy allowlist is closed. App\Models holds nothing (User
     * exited to Identity\Domain\User, #33) and App\Http\Controllers only
     * Controller; each exit removes its entry.
     */
    public static function legacyAllowlistIsClosed(string $appDirectory): void
    {
        $violations = [];

        foreach (['Models' => [], 'Http/Controllers' => ['Controller.php']] as $directory => $allowed) {
            foreach (self::phpFilesUnder("{$appDirectory}/{$directory}") as $file) {
                if (! in_array($file, $allowed, true)) {
                    $violations[] = "{$directory}/{$file}";
                }
            }
        }

        expect($violations)->toBe([], 'Outside the closed legacy allowlist: '.implode(', ', $violations));
    }

    /**
     * C3: App\Modules holds only the 13 modules, each module only the six
     * sub-namespaces, and a directory exists only once a class is written in
     * it (ADR-0010 §1, owner decision D2).
     */
    public static function moduleTreeIsCanonical(string $modulesDirectory): void
    {
        $violations = self::moduleTreeViolations($modulesDirectory);

        expect($violations)->toBe([], 'App\Modules breaks C3: '.implode('; ', $violations));
    }

    /**
     * No App\Modules directory, before the first module class, is a
     * canonical tree.
     *
     * @return list<string>
     */
    private static function moduleTreeViolations(string $modulesDirectory): array
    {
        if (! is_dir($modulesDirectory)) {
            return [];
        }

        $violations = [];

        foreach (new \FilesystemIterator($modulesDirectory) as $entry) {
            $name = $entry->getFilename();

            if (! $entry->isDir() || ! in_array($name, Registry::MODULES, true)) {
                $violations[] = "{$name} is not a module";

                continue;
            }

            foreach (new \FilesystemIterator($entry->getPathname()) as $child) {
                if (! $child->isDir() || ! in_array($child->getFilename(), Registry::SUB_NAMESPACES, true)) {
                    $violations[] = "{$name}/{$child->getFilename()} is not a sub-namespace";
                }
            }
        }

        $directories = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulesDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($directories as $directory) {
            if ($directory->isDir() && self::phpFilesUnder($directory->getPathname()) === []) {
                $relative = substr($directory->getPathname(), strlen($modulesDirectory) + 1);
                $violations[] = str_replace('\\', '/', $relative).' has no class';
            }
        }

        return $violations;
    }

    /**
     * PHP files under a directory, relative to it, with forward slashes.
     *
     * @return list<string>
     */
    private static function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            }
        }

        sort($files);

        return $files;
    }

    /**
     * M1: a module imports another module only from its Contracts.
     *
     * Written as "uses no other module, except its Contracts", so it covers
     * every namespace of the other module, not only the listed sub-namespaces.
     */
    public static function modulesImportOnlyContracts(string $modules): void
    {
        foreach (Registry::MODULES as $module) {
            $others = array_values(array_diff(Registry::MODULES, [$module]));

            expect("{$modules}\\{$module}")
                ->not->toUse(array_map(fn (string $other): string => "{$modules}\\{$other}", $others))
                ->ignoring(array_map(fn (string $other): string => "{$modules}\\{$other}\\Contracts", $others));
        }
    }

    /** M2: the shared kernel imports no module. */
    public static function sharedImportsNoModule(string $shared, string $modules): void
    {
        expect($shared)->not->toUse($modules);
    }

    /** M4: a module calls only the Contracts the dependency matrix allows. */
    public static function onlyAllowedDependencies(string $modules): void
    {
        foreach (Registry::DEPENDENCIES as $module => $allowed) {
            $forbidden = array_values(array_diff(Registry::MODULES, [$module], $allowed));

            expect("{$modules}\\{$module}")
                ->not->toUse(array_map(fn (string $callee): string => "{$modules}\\{$callee}\\Contracts", $forbidden));
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
