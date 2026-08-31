# ADR-0003: Context-dependent vendor scoping (retire the blanket global scope)

**Status:** Accepted
**Date:** 2026-08-30

---

## Context

Today, `BelongsToTenant` adds a global `TenantScope` to ~21 models. Every query is
filtered to the current tenant unless `withoutTenancy()` is called explicitly. This was
correct for isolated stores: a shopper on `acme.example.com` must only ever see acme's
data.

In a marketplace this assumption splits in two:

| Context | Correct behaviour |
|---------|-------------------|
| **Storefront** (shopper browsing catalog, search, a product page, their cart, their orders) | See products/categories from **all** vendors. Not vendor-scoped. |
| **Vendor dashboard / vendor API** (a vendor's team working their store) | See **only** that vendor's products, orders, inventory, customers. Strictly vendor-scoped. |
| **Platform control plane** (operator) | See everything; writes audit-logged. |

A blanket global scope is now wrong for the storefront (it would hide 98% of the catalog)
and still essential for the vendor dashboard (its absence leaks other vendors' orders).

The global scope is currently the **only** thing preventing that leak. Removing it
carelessly is the classic marketplace data-breach.

## Decision

1. **Remove the global `TenantScope` from `BelongsToTenant`.** Models no longer
   auto-filter.
2. **Auto-set `tenant_id` on create stays** — that behaviour is still wanted everywhere.
3. **Scoping becomes explicit and context-driven:**
   - **Storefront controllers/queries:** no vendor filter. They query the whole catalog.
     Product visibility is governed by `is_active` + vendor `approved` state, not by
     scope.
   - **Vendor context:** a `CurrentVendor` resolved from the authenticated vendor-team
     user (not from a subdomain). A `ScopedToCurrentVendor` query scope / trait method is
     applied **explicitly** in every vendor-dashboard and vendor-API query, and enforced
     by a base controller / form-request / policy layer so it cannot be forgotten silently.
   - **Operator context:** unscoped by default; destructive or money-moving writes go
     through audited actions.
4. **A dedicated test suite** (`tests/Feature/MultiTenancy/` → renamed
   `tests/Feature/VendorScoping/`) asserts, for every vendor-facing endpoint, that vendor
   A cannot read or mutate vendor B's records. This suite is a merge gate.
5. **An architecture test** asserts that every model using `BelongsToTenant` is either
   (a) referenced only through the explicit vendor scope in vendor contexts, or
   (b) on an allow-list of "storefront-public" models — so a new model can't quietly
   become unscoped-everywhere.

## Alternatives considered

- **Keep the global scope; add a `withAllVendors()` bypass on every storefront query.**
  Rejected: inverts the danger — forgetting the bypass is a broken storefront (loud,
  caught in dev), but the volume of bypass calls is large and the intent gets muddy.
  Also fights the framework on every catalog read.
- **Two global scopes toggled by a middleware flag** (`storefront` vs `vendor`). Rejected:
  hidden global state that depends on request type is exactly what makes tenancy bugs hard
  to reason about; queue jobs and console commands have no request.
- **Separate read-model tables for the storefront catalog.** Rejected: premature at pilot
  scale; the projections infrastructure exists if we ever need it.

## Consequences

- **Easier:** the storefront becomes a normal Laravel app querying a shared catalog;
  search no longer needs a tenant filter; operator queries stop needing `withoutTenancy()`
  everywhere.
- **Harder / riskier:** the safety net is gone. Correctness now depends on the explicit
  vendor scope being applied in every vendor context. This is mitigated by the base-layer
  enforcement + the scoping test suite being a merge gate — **these are not optional and
  must land in the same change that removes the global scope.**
- **Migration ordering:** remove-global-scope and add-explicit-scoping ship together, with
  the test suite, or not at all. Documented in
  [../marketplace-migration.md](../marketplace-migration.md).
- **Follow-up:** delete `ResolveTenantFromSubdomain` for the storefront (ADR-0002 territory:
  single domain); `CurrentVendor` resolver from the auth user; update
  `tests/Traits/WithTenant.php` and `TenantResolutionTest`.
- **`INVARIANTS.md`** gains an explicit invariant: "Vendor-context queries are scoped to
  the authenticated vendor; storefront catalog queries are not scoped."
