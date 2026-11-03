# ADR-0010: Structure the application as a modular monolith with enforced module contracts

- **Number:** 0010
- **Status:** Proposed
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

2. **Shared kernel** `app/Shared/`. This is the only code every module may use:
   - `Money`;
   - `Clock`;
   - public ID generation;
   - `Outbox` writer;
   - `Idempotency`;
   - `Audit` recorder;
   - the base exception types.

   Nothing business-specific belongs here.

3. **Module list and allowed dependencies** are defined in `docs/architecture/domain-context-map.md` and `dependency-rules.md`. Those documents are normative.

4. **Cross-module communication:**
   - **Synchronous:** call a `Contracts` interface, implemented inside the owning module and bound in its service provider. Used when the caller needs an answer inside its own use case (checkout asks Inventory to reserve).
   - **Asynchronous:** integration events published through the transactional outbox (ADR-0017). Used for reactions (search re-index, notifications, ledger postings that follow a state change).

5. **Data ownership.** Each table has exactly one owning module (`database-design.md`). Only the owner writes it.
   - Other modules never query it with Eloquent relationships or joins.
   - The single exception is read-only reporting queries in `Application/Queries` of the **Finance** and **Platform admin** read models. These are listed explicitly in `dependency-rules.md`.

6. **Foreign keys across modules are allowed** for referential integrity, with `ON DELETE RESTRICT`. Eloquent relationships across modules are not.

7. **Enforcement** in `tests/Arch/`:
   - module A may not import `App\Modules\B\{Domain,Application,Infrastructure,Http}`;
   - `Domain` may not import `Illuminate\Http`, `Inertia`, `Filament` or `Stripe`;
   - controllers may not call `DB::` or Eloquent writes directly;
   - no `env()` outside `config/` (existing rule).

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

- Arch tests for every rule.
- Each `Contracts` interface has a contract test, run against the real implementation and, where a fake exists, against the fake.

## Migration implications

None. The application is greenfield on `main`.

## Related

- `docs/architecture/domain-context-map.md`, `dependency-rules.md`, ADR-0017.
