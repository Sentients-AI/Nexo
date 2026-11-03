# ADR-0020: Build platform administration with Filament; build the vendor dashboard in Vue

- **Number:** 0020
- **Status:** Accepted, by owner decision (2026-11-04)
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Owner decision (2026-11-04)

The owner accepted this ADR before Phase 2:

- Filament is used for platform administration at `/admin`, for authorised platform staff only.
- The vendor dashboard stays Vue 3 + Inertia.js (ADR-0008).
- Filament uses the Phase 2 design tokens.
- The admin interface is English-only in v2.
- Filament resources and actions delegate business operations to Application use cases. They never mutate domain status or financial data directly.
- Laravel 13 compatibility is verified in Phase 3. The fallback in Decision §5 is retained.

**Rationale** (the decision drivers below):

- Filament delivers dense back-office tooling for a staff-only surface at low cost.
- Vendors keep a product-grade, localised Vue experience.
- Keeping vendors out of Filament avoids the panel-tenancy pattern that Phase 0 found unsafe (S1).

**Dependency on a Proposed ADR.** This ADR relies on mechanisms defined by ADR-0011, which is still **Proposed**:

- `platform_role`;
- the permission enum;
- `VendorContext`;
- the rule that staff cannot hold a vendor membership.

Decision §1 and §6 name these mechanisms as the intended design. They are not accepted until ADR-0011 is. If ADR-0011 changes, §1 and §6 follow it, through a new ADR (ADR-0006).

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
6. **Surface separation.** The two back-office surfaces do not overlap:

   | | Platform administration | Vendor dashboard |
   |---|---|---|
   | Route | `/admin` | `/vendor` |
   | Technology | Filament (Livewire) | Vue 3 + Inertia.js |
   | Who | Users with `platform_role` (staff) | Vendor members, resolved through `VendorContext` (ADR-0011) |
   | Languages | English only | `en`, `ar` (RTL), `ms` |

   - No Filament panel, resource or Filament tenancy is used for vendors.
   - A vendor member without `platform_role` is denied at `/admin`.
   - Under ADR-0011 (Proposed), staff cannot hold a vendor membership, so they never use `/vendor`.
   - Both surfaces call the same Application use cases and policies. Business rules are not duplicated in either UI.
7. **Language.** The admin interface is English-only in v2. Arabic, Malay and right-to-left requirements apply to the storefront and the vendor dashboard.

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
