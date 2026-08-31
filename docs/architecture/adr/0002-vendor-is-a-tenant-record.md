# ADR-0002: A "vendor" is the existing tenant record, reframed

**Status:** Accepted
**Date:** 2026-08-30

---

## Context

The system was built as a multi-tenant SaaS: `tenants` table, `Tenant` model, `tenant_id`
on ~21 models, subdomain resolution, a global `TenantScope`. See `docs/DECISIONS.md`
entries from 2026-02-07.

The product is now a single marketplace (see [../../product/vision.md](../../product/vision.md)).
There is exactly one "platform". What used to be a tenant (an isolated store) is now a
**vendor** (a seller listing into a shared catalog).

We must decide how far to carry the rename into the schema and code, given pilot scale and
a live database with real data on `store.aljebal-albeedos.com`.

## Decision

1. **Keep the `tenants` table and the `Tenant` Eloquent model.** Do not rename the table
   or the class in V1.
2. **Reframe the bounded context in documentation and UI as "Vendor".** User-facing
   strings, admin nav, and new code comments say "vendor". `app/Domain/Tenant` keeps its
   directory name for now.
3. **`tenant_id` columns keep their name** but mean "owning vendor". A future ADR may
   rename to `vendor_id` if it proves cheap; it is not worth a large migration at pilot
   scale.
4. **There is no `Platform` entity.** "The platform" is the deployment. Platform operators
   are users with no `tenant_id` and the super-admin role, exactly as today.
5. New marketplace fields (already started on the `redesign` branch:
   `add_marketplace_fields_to_tenants_table`) live on `tenants` — approval state, Stripe
   Connect account id, commission overrides later.

## Alternatives considered

- **Rename `tenants` → `vendors` everywhere now.** Rejected: touches ~21 models, factories,
  seeders, 100+ migrations' worth of assumptions, and a live DB, for a cosmetic gain. High
  risk, no functional benefit at this scale.
- **Introduce a separate `Vendor` model alongside `Tenant`.** Rejected: two models for one
  concept guarantees drift and confusion. The tenant *is* the vendor.
- **Add a `Platform` / `Marketplace` model for future multi-tenancy of marketplaces.**
  Rejected: speculative. [vision.md](../../product/vision.md) says one marketplace; a
  second is a new product decision.

## Consequences

- **Easier:** minimal migration; existing relationships, factories, and the Filament
  `TenantResource` keep working; the pivot is mostly semantic + scoping (ADR-0003) +
  money (ADR-0005, pending).
- **Harder:** a naming mismatch between code (`tenant`, `Tenant`, `tenant_id`) and
  language (`vendor`) — new developers must be told once, in
  `docs/development/` and this ADR.
- **Follow-up:**
  - Filament `TenantResource` → relabel to "Vendors"; add approval + Connect fields.
  - `docs/COMPREHENSIVE_CODEBASE_GUIDE.md` and `README.md` updated to say vendor/marketplace.
  - A later ADR decides whether to rename `app/Domain/Tenant` → `app/Domain/Vendor` and
    `tenant_id` → `vendor_id` once the migration settles.
