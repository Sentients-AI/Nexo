# ADR-0003: Context-dependent vendor scoping (retire the blanket global scope)

**Status:** Accepted. Implementation interpretation accepted by the owner, not a change to the decision: (1, 2026-11-22) the `Tenant`, `TenantScope`, `BelongsToTenant` and `tenant_id` names here come from ADR-0002, which ADR-0005 superseded; the code spells them `Vendor` and `vendor_id`. The decision stands unchanged: no global vendor or tenant scope is reintroduced, scoping stays explicit and context-driven, the owning `vendor_id` is set on create, and the vendor-scoping suite and architecture test remain the enforcement.
**Date:** 2026-08-30

---

## Context

The standard Laravel tenancy pattern (ADR-0002) has `BelongsToTenant` add a global
`TenantScope` to every owned model. Every query is filtered to the current tenant unless
`withoutTenancy()` is called explicitly. That is correct for isolated stores: a shopper on
`acme.example.com` must only ever see acme's data.

In a marketplace this assumption splits in two:

| Context | Correct behaviour |
|---------|-------------------|
| **Storefront** (shopper browsing catalog, search, a product page, their cart, their orders) | See products/categories from **all** vendors. Not vendor-scoped. |
| **Vendor dashboard / vendor API** (a vendor's team working their store) | See **only** that vendor's products, orders, inventory, customers. Strictly vendor-scoped. |
| **Platform control plane** (operator) | See everything; writes audit-logged. |

A blanket global scope is wrong for the storefront (it would hide 98% of the catalog)
and still essential for the vendor dashboard (its absence leaks other vendors' orders).

Whatever replaces the global scope becomes the **only** thing preventing that leak.
Getting it wrong is the classic marketplace data breach.

## Decision

1. **No global `TenantScope` on `BelongsToTenant`.** Models do not auto-filter.
2. **`tenant_id` is set automatically on create.** That behaviour is wanted everywhere.
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
4. **A dedicated test suite** (`tests/Feature/VendorScoping/`) asserts, for every vendor-facing endpoint, that vendor
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
- **Separate read-model tables for the storefront catalog.** Rejected: premature at launch
  scale; a projection can be added behind the search port if it is ever needed.

## Consequences

- **Easier:** the storefront is a normal Laravel app querying a shared catalog;
  search needs no tenant filter; operator queries never need `withoutTenancy()`.
- **Harder / riskier:** there is no safety net. Correctness depends on the explicit
  vendor scope being applied in every vendor context. The base-layer enforcement and the
  scoping test suite as a merge gate are what make that safe, so **they are not optional
  and land with the first vendor-scoped model.**
- **Follow-up:** a `CurrentVendor` resolver from the authenticated user (one domain, no
  subdomain resolution).
- **`INVARIANTS.md`** gains an explicit invariant: "Vendor-context queries are scoped to
  the authenticated vendor; storefront catalog queries are not scoped."
