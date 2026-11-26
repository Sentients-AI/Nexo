<?php

use Tests\Arch\Support\MarkdownTable;
use Tests\Arch\Support\Registry;

// The registry must match the normative documents (ADR-0010 §7). A change to
// the documents and the registry goes in the same commit.

it('lists the modules of the domain context map', function () {
    $rows = MarkdownTable::rowsAfter('docs/architecture/domain-context-map.md', '## 2. Module responsibilities');
    $modules = array_values(array_filter(array_column($rows, 0), fn (string $name): bool => $name !== 'Shared'));

    expect($modules)->toBe(Registry::MODULES);
});

it('has the dependency matrix of dependency-rules.md §2', function () {
    $heading = '### Synchronous dependency matrix';
    // The last column is abbreviated in the document.
    $callees = array_map(
        fn (string $name): string => $name === 'Notif.' ? 'Notifications' : $name,
        array_slice(MarkdownTable::headerAfter('docs/architecture/dependency-rules.md', $heading), 1),
    );

    expect($callees)->toBe(Registry::MODULES);

    $matrix = [];

    foreach (MarkdownTable::rowsAfter('docs/architecture/dependency-rules.md', $heading) as $row) {
        // A tick, with or without a footnote, allows the call. A footnote alone
        // (Finance, footnote 3) marks a deliberately empty cell.
        $matrix[trim($row[0], '*')] = array_values(array_filter(
            $callees,
            fn (string $callee, int $i): bool => str_starts_with($row[$i + 1] ?? '', '✓'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    expect($matrix)->toBe(Registry::DEPENDENCIES);
});

it('has the sub-namespaces of rule C3', function () {
    $rows = MarkdownTable::rowsAfter('docs/architecture/dependency-rules.md', '### Composition root and transitional legacy');
    $c3 = array_values(array_filter($rows, fn (array $row): bool => $row[0] === 'C3'))[0][1] ?? '';

    preg_match('/Each contains only (.+?), and each directory/', $c3, $list);
    preg_match_all('/`(\w+)`/', $list[1] ?? '', $names);

    expect($names[1])->toBe(Registry::SUB_NAMESPACES);
});

it('has the deferred rules and triggers of dependency-rules.md §6', function () {
    $deferred = [];

    foreach (MarkdownTable::rowsAfter('docs/architecture/dependency-rules.md', '## 6. Deferred rules') as $row) {
        preg_match('/^[A-Z]\d+/', $row[0], $id);
        $deferred[$id[0] ?? $row[0]] = $row[2];
    }

    expect($deferred)->toBe(Registry::DEFERRED);
});

it('has the PHPStan-enforced rules of dependency-rules.md §1, each registered', function () {
    $enforced = [];

    foreach (MarkdownTable::rowsAfter('docs/architecture/dependency-rules.md', '## 1. Layer rules') as $row) {
        if (preg_match('/PHPStan rule `([^`]+)`/', $row[2] ?? '', $class) === 1) {
            $enforced[$row[0]] = $class[1];
        }
    }

    expect($enforced)->toBe(Registry::PHPSTAN_RULES);

    $config = (string) file_get_contents(dirname(__DIR__, 2).'/phpstan.neon.dist');

    foreach (Registry::PHPSTAN_RULES as $rule) {
        expect($config)->toMatch('/^\s*-\s*'.preg_quote($rule, '/').'\s*$/m');
    }
});

it('has the planned rules of dependency-rules.md §7, none of them enforced', function () {
    $planned = [];

    foreach (MarkdownTable::rowsAfter('docs/architecture/dependency-rules.md', '## 7. Planned rules', allowEmpty: true) as $row) {
        $planned[$row[0]] = $row[3];
    }

    expect($planned)->toBe(Registry::PLANNED)
        ->and(array_intersect_key($planned, Registry::PHPSTAN_RULES))->toBe([]);
});
