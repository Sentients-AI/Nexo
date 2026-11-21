# ADR-0010: Structure the application as a modular monolith with enforced module contracts

- **Number:** 0010
- **Status:** Accepted, by owner decision M6-D-04 (2026-11-15), as amended. The package-adoption text proposed with M6-D-04 is not included: MySQL first, and first-party packages are deferred. Implementation interpretations accepted by the owner, not changes to the decision: (1, 2026-11-21) §4's "bound in its service provider" means a provider in the module's own `Infrastructure/`, registered from `bootstrap/providers.php`; so under §2a the composition root imports a module's `Contracts`, `Http`, `Filament` and `Infrastructure`, never its `Domain` or `Application`; (2, 2026-11-21) §1's rule that a directory exists only once its first class does also binds M1 Slice B, which creates no module or `Shared` directory ahead of code. M1 Slice B was delivered as M6.1 (#23).
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

The brief (§5, §9) prefers a modular monolith with explicit boundaries, controlled cross-domain communication and automated dependency checks.

Phase 0 found that the old repository had 29 `app/Domain` folders with free cross-imports:

- a domain-event store nobody consumed;
- controllers that bypassed state machines.

`docs/engineering/architecture-guidelines.md` already forbids generic services, repositories that merely wrap Eloquent, and interfaces without a second implementation or test seam.

## Problem

How is code organised into modules, what may a module depend on, and how is that enforced?

## Decision drivers

- Boundaries a reviewer can see in the directory tree and verify in CI.
- Pragmatism: Eloquent stays the persistence model, with no purist repository layer.
- Cross-module calls are explicit and few.

## Considered alternatives

### A. Laravel default layout (`app/Models`, `app/Http`, …)

Rejected. There are no visible boundaries, and the old repository's failure mode would come back.

### B. Purist hexagonal design per module (domain entities separate from Eloquent, repositories everywhere)

Rejected. It doubles the model code and contradicts the existing guidelines. That cost is not justified at this scale.

### C. Pragmatic modules with a public `Contracts` surface, enforced by Pest `arch()` (chosen)

### D. As C, plus deptrac

Deferred. Pest `arch()` (already installed) can express every rule below. Deptrac is added only if a rule cannot be expressed.

## Decision

1. **Layout.** One folder per module under `app/Modules/<Module>/`, with these sub-namespaces:

   | Sub-namespace | Contents |
   |---|---|
   | `Domain/` | Eloquent models owned by the module, value objects, enums, state machines, domain exceptions and domain events. No HTTP, session, Inertia, Filament or provider SDK. |
   | `Application/` | Use-case classes (`PlaceCheckout`, `ReserveStock`), one public `handle()` each, and the module's transaction boundaries. Query objects for reads. |
   | `Infrastructure/` | Provider adapters (Stripe), queue jobs, listeners, outbox consumers, console commands. |
   | `Http/` | Controllers, form requests, Inertia page props (DTOs) and route files. Vendor, storefront and API controllers live in separate sub-folders. |
   | `Contracts/` | **The only namespace other modules may import:** interfaces (`InventoryReservations`), immutable DTOs, integration events and enums that cross the boundary. |
   | `Filament/` | Admin resources for this module (ADR-0020). |

   - A module directory, and each of its sub-namespaces, is created only when the first class that belongs there is written. The module list (§3) is fixed; the directory tree is not created in advance.
   - `Filament/` follows the same rule: it exists only once Filament is installed (ADR-0020) and the module has an admin resource.
   - No other sub-namespace is allowed directly under a module.

2. **Shared kernel** `app/Shared/`. This is the only non-module code every module may use:
   - `Money`;
   - `Clock`;
   - public ID generation;
   - `Outbox` writer;
   - `Idempotency`;
   - `Audit` recorder;
   - the base exception types.

   Each member is created when the first use case needs it. `Shared` imports no module. Nothing business-specific belongs here.

2a. **Composition root.** Code that wires the application together and belongs to no module. It is permanent:
   - `App\Providers` (service providers and container bindings);
   - kernel-level `App\Http`: global middleware (`SecurityHeaders`, `SetLocale`, `HandleInertiaRequests`), `App\Http\Errors` and `App\Http\Localization`;
   - the `bootstrap/` and `routes/` files.

   The composition root may import `App\Shared` and module `Contracts`. It may import a module's `Http`, `Filament` or `Infrastructure` classes only to register them (routes, providers, bindings, panels). No module imports the composition root. Module controllers, form requests and module middleware (for example `vendor.context`) live in the module's `Http/`, not here.

2b. **Transitional legacy.** Two classes predate the modules. They are allowed only until their exit milestone:

   | Class | Exit | Until then |
   |---|---|---|
   | `App\Models\User` | Moves to `Identity\Domain\User` when Identity is implemented (M3) | Imported only by the composition root, `config/`, factories and Identity |
   | `App\Http\Controllers\Controller` | Removed or moved when the first module controller is written (M3) | Not extended by module code |

   The allowlist is closed: no other class may be added under `App\Models` or `App\Http\Controllers`. Each exit removes its row in the same change.

3. **Module list and allowed dependencies** are defined in `docs/architecture/domain-context-map.md` and `dependency-rules.md`. Those documents are normative.

4. **Cross-module communication:**
   - **Synchronous:** call a `Contracts` interface, implemented inside the owning module and bound in its service provider. Used when the caller needs an answer inside its own use case (checkout asks Inventory to reserve).
   - **Asynchronous:** integration events published through the transactional outbox (ADR-0017). Used for reactions (search re-index, notifications, ledger postings that follow a state change).
   - **Ports.** A module may declare an interface in its `Contracts` that another module implements (dependency inversion). The implementing module depends on the port owner, never the reverse. Example: `Payments\Contracts\OrderPayments` is declared by Payments and implemented by Ordering, so Payments never depends on Ordering.

5. **Data ownership.** Each table has exactly one owning module (`database-design.md`). Only the owner writes it.
   - Other modules never query it with Eloquent relationships or joins.
   - The only exceptions are the read-only queries listed in `dependency-rules.md` §4, each in its module's `Application/Queries`.

6. **Foreign keys across modules are allowed** for referential integrity, with `ON DELETE RESTRICT`. Eloquent relationships across modules are not.

7. **Enforcement** in `tests/Arch/`, its own test suite, run in the required CI check. It is introduced by M1 Slice B; until then only L6 and L7 are enforced (`tests/Unit/ArchTest.php`).
   - A machine-readable registry of the modules and their allowed dependencies lives in `tests/Arch/`. It must match `dependency-rules.md`, and a drift test enforces this.
   - Enforced from M1 Slice B: L1, L2, L3 (no `DB` facade in controllers), L5, L6, L7; M1, M2, M4, M5; module and sub-namespace structure (§1, rule C3); composition-root and legacy boundaries (§2a, §2b, rules C1 and C2).
   - Each enforced rule is proven by a deliberate-violation fixture that the rule rejects.
   - Deferred, each with its trigger in `dependency-rules.md` §6: the method-call part of L3, L4, M3, M6, F2 and F3.
   - Deptrac stays deferred (alternative D).

   Arch tests are a CI gate.

## Positive consequences

- Boundaries are visible, testable and reviewable.
- Each module can be read in isolation.

## Negative consequences

- More files and DTO mapping at the boundaries.
- Some reads need a contract method instead of a join.

## Risks

- Over-modularisation of small concerns. Mitigation: the module list is deliberately short (13 modules), and new modules need an ADR amendment.

## Testing implications

- Arch tests for every enforced rule, each with a deliberate-violation fixture.
- Each `Contracts` interface has a contract test, run against the real implementation and, where a fake exists, against the fake.

## Migration implications

None. The application is greenfield on `main`.

## Related

- `docs/architecture/domain-context-map.md`, `dependency-rules.md`, ADR-0017.
