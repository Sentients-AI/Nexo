# ADR-0005: Use Vendor as the Marketplace Seller Entity

- Status: Accepted (owner, 2026-11-22); supersedes ADR-0002. Implementation interpretation accepted by the owner, not a change to the decision: (1, 2026-11-22) the Vendor is persisted in a module-owned `vendors` table, owned by the Vendors module (ADR-0010; `domain-context-map.md` §2). Nexo has no `tenants` table or `Tenant` model, and none is introduced.
- Date: 2026-09-10

## Context

Nexo is a single multi-vendor marketplace. The marketplace contains independent sellers who share one storefront.

ADR-0002 named the seller `Tenant`, borrowing the vocabulary of Laravel's multi-tenancy tooling. Nothing has been built on that name yet, so changing it now costs nothing.

The Vendor domain is being designed now. The marketplace concept is a seller, not an isolated tenant or storefront.

## Decision

The marketplace domain uses explicit `Vendor` terminology for the seller entity.

A Vendor has its own stable identity and lifecycle and exists independently of user accounts. Vendor-owned data will reference the Vendor explicitly where required.

The design will use `Vendor` consistently in new marketplace domain concepts, documentation, relationships, and application code.

## Why

Using `Vendor` reflects the actual business meaning of the entity and makes the marketplace architecture easier to understand.

`Tenant` is SaaS multi-tenancy vocabulary. It implies that each Vendor owns an isolated storefront, and it invites the global-scope pattern ADR-0003 rejects.

The convenience that motivated ADR-0002, reusing tenancy tooling as-is, does not survive ADR-0003: Nexo scopes explicitly, so the tooling buys nothing.

## Consequences

### Positive

- The domain language matches the marketplace business model.
- Vendor ownership and isolation boundaries are explicit.
- New developers can distinguish Vendors from marketplace-wide shoppers and Platform Operators.
- The design avoids speculative multi-marketplace or multi-tenant abstractions.

### Negative

- Documents written before this ADR (ADR-0002, ADR-0003) still say `Tenant` and `tenant_id`; readers translate them to `Vendor` and `vendor_id`.
- Laravel and Filament tenancy features cannot be used off the shelf, because they assume the `Tenant` vocabulary and a global scope.

## Alternatives Considered

### Continue using Tenant

Rejected because `Tenant` represents an isolated SaaS store, while Nexo is one shared marketplace containing multiple Vendors.

### Support both Tenant and Vendor

Rejected because it would introduce unnecessary complexity and two concepts for the same marketplace seller boundary.

## Scope

This decision applies to the new Vendor domain design. It does not implement database migrations, onboarding, authorization, payments, or other Vendor functionality.