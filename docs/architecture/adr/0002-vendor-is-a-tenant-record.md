# ADR-0002: Model the seller as a tenant record

**Status:** Superseded by ADR-0005 (owner, 2026-11-22)
**Date:** 2026-08-30

---

## Context

Nexo is one marketplace with many sellers. Every seller-owned row (products, inventory,
orders, payouts) needs an owner column, and something has to stop one seller's team from
reading another seller's data.

The Laravel ecosystem already has a well-worn vocabulary for this: a `Tenant` model, a
`tenant_id` column, a `BelongsToTenant` trait and a global `TenantScope` (Filament's panel
tenancy and most tenancy packages use the same names). Adopting that vocabulary means
less to invent and less to explain.

## Decision

1. **The seller is a `Tenant` record** in a `tenants` table, with a `Tenant` Eloquent model.
2. **The UI and documentation say "vendor".** User-facing strings and admin navigation
   use the business word; the code keeps the framework word.
3. **`tenant_id` means "owning vendor"** on every seller-owned table.
4. **There is no `Platform` entity.** "The platform" is the deployment. Platform operators
   are users with no `tenant_id` and the super-admin role.
5. Marketplace fields (approval state, payout account, commission overrides) live on
   `tenants`.

## Alternatives considered

- **Name it `Vendor` from the start.** Rejected at the time: the tenancy vocabulary comes
  with ready-made scoping and Filament integration, and the naming gap looked cosmetic.
- **A separate `Vendor` model alongside `Tenant`.** Rejected: two models for one concept
  guarantees drift.
- **A `Platform` / `Marketplace` model for multiple marketplaces.** Rejected: speculative.
  Nexo is one marketplace; a second is a new product decision.

## Consequences

- **Easier:** scoping, factories and the Filament resource follow the patterns the
  ecosystem documents.
- **Harder:** code says `tenant` where the business says `vendor`, and every new developer
  has to be told once. "Tenant" also suggests an isolated store, which a shared
  marketplace is not.
- **Follow-up:** a later ADR may rename `Tenant` to `Vendor` and `tenant_id` to `vendor_id`.
  ADR-0005 did, and superseded this one.
