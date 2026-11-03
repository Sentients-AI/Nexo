# ADR-0020: Build platform administration with Filament; build the vendor dashboard in Vue

- **Number:** 0020
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

- The brief (§6.3) asks to *evaluate and improve* Filament rather than assume replacement.
- The old repository used Filament (v5.7) for an admin panel and Vue for the vendor area.
- Platform staff need dense back-office CRUD, queues and audit views. Vendors need a product-grade, branded, localised experience (Phase 2 design system).

## Problem

Which technology serves the platform-administration and vendor surfaces?

## Decision drivers

- Speed of delivery for back-office tooling.
- Product-grade UX for vendors.
- Consistent authorisation.

## Considered alternatives

### A. Filament for both admin and vendor

- Fast to build.
- Rejected: the vendor experience would look like a generic admin, and Filament's tenancy model invites the global-scope pattern Phase 0 found unsafe.

### B. Vue for both

- One frontend stack.
- Rejected: it rebuilds tables, filters, forms and actions that Filament provides, for a surface only staff see.

### C. Filament for platform staff at `/admin`; Vue + Inertia for vendors at `/vendor` and customers at `/` (chosen)

## Decision

1. Filament serves only users with `platform_role`. The panel is gated by `canAccessPanel()`. Every resource and action is gated by policies using the permission enum (ADR-0011).
2. Filament resources live in each module's `Filament/` namespace. They call Application use cases for every state change: approve vendor, override return, retry refund, resolve reconciliation exception. **Filament never writes status or money columns directly.**
3. Admin dashboards (GMV, orders, refunds, payables, outbox and reconciliation health) read from Finance and Platform read models.
4. Filament is themed with the Phase 2 design tokens (colours, typography) so it looks like part of the same product.
5. **Compatibility check:** Filament's support for Laravel 13 / PHP 8.5 is to be verified in Phase 3 before installation. If it is unsupported, this ADR is revisited. The fallback is Vue admin pages built from the same component kit.

## Positive consequences

- Rich operator tooling at low cost.
- Vendor UX is uncompromised.

## Negative consequences

- Two UI technologies (Livewire for admin, Vue for the rest).
- The admin is localised in `en` only for v2 (stated limitation).

## Risks

- Direct Eloquent edits through generic Filament forms bypass use cases. Mitigation: status and money fields are read-only in forms, and an arch test forbids `Filament` namespaces from importing Domain models' state-machine setters.

## Testing implications

- Livewire/Filament tests per resource: access denied for non-staff and wrong role; actions invoke use cases; audit entries written.

## Migration implications

None.

## Related

- ADR-0011, `security-model.md`.
