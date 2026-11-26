<?php

namespace Tests\Arch\Support;

use Tests\Arch\PHPStan\AuditLogMutationRule;
use Tests\Arch\PHPStan\MembershipWriteRule;
use Tests\Arch\PHPStan\VendorCreationRule;
use Tests\Arch\PHPStan\VendorOrchestrationRule;
use Tests\Arch\PHPStan\VendorStatusWriteRule;

/**
 * The machine-readable module registry (ADR-0010 §7). DriftTest keeps it equal
 * to docs/architecture/dependency-rules.md and domain-context-map.md, so a
 * change to either side alone fails the suite.
 */
final class Registry
{
    /** ADR-0010 §3, domain-context-map.md §2. */
    public const MODULES = [
        'Identity', 'Vendors', 'Catalogue', 'Inventory', 'Shipping', 'Ordering', 'Payments',
        'Finance', 'Promotions', 'Loyalty', 'Engagement', 'Search', 'Notifications',
    ];

    /** ADR-0010 §1, rule C3. Only Contracts may be imported by another module (M1). */
    public const SUB_NAMESPACES = ['Domain', 'Application', 'Infrastructure', 'Http', 'Contracts', 'Filament'];

    /**
     * The synchronous dependency matrix (dependency-rules.md §2, rule M4):
     * caller => the modules whose Contracts it may call.
     *
     * @var array<string, list<string>>
     */
    public const DEPENDENCIES = [
        'Identity' => [],
        'Vendors' => ['Identity'],
        'Catalogue' => ['Vendors'],
        'Inventory' => ['Vendors', 'Catalogue'],
        'Shipping' => ['Vendors'],
        // Payments is a port declared by Payments and implemented by Ordering (footnote 2).
        'Ordering' => ['Identity', 'Vendors', 'Catalogue', 'Inventory', 'Shipping', 'Payments', 'Finance', 'Promotions', 'Loyalty'],
        'Payments' => [],
        // Deliberately not Ordering or Payments (footnote 3).
        'Finance' => ['Vendors'],
        'Promotions' => ['Catalogue'],
        'Loyalty' => ['Identity'],
        'Engagement' => ['Identity', 'Vendors', 'Catalogue', 'Ordering'],
        'Search' => ['Vendors', 'Catalogue', 'Inventory', 'Engagement'],
        'Notifications' => ['Identity', 'Vendors', 'Ordering'],
    ];

    /**
     * Rules that are normative but not yet enforced, with their triggers
     * (dependency-rules.md §6). Each is enforced, with a fixture, no later than
     * its trigger.
     *
     * @var array<string, string>
     */
    public const DEFERRED = [
        'L3' => 'First module with controllers and Eloquent models',
        'M3' => 'Second module that owns tables',
        'M6' => 'ADR-0017 accepted and the first integration event',
        'F2' => 'First typed page contract',
        'F3' => 'First page that renders server money',
    ];

    /**
     * The rules of dependency-rules.md §1 enforced by a PHPStan rule, each
     * registered in phpstan.neon.dist.
     *
     * @var array<string, class-string>
     */
    public const PHPSTAN_RULES = [
        'L4' => VendorStatusWriteRule::class,
        'L8' => VendorCreationRule::class,
        'L9' => VendorOrchestrationRule::class,
        'L10' => AuditLogMutationRule::class,
        'L11' => MembershipWriteRule::class,
    ];

    /**
     * Rules that are decided but not implemented, with the task each lands
     * with (dependency-rules.md §7). Nothing enforces them yet.
     *
     * @var array<string, string>
     */
    public const PLANNED = [];
}
