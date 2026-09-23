# ADR-0005: Use Vendor as the Marketplace Seller Entity

- Status: Proposed
- Date: 2026-09-10

## Context

Nexo is being developed as a single multi-vendor marketplace for aljebal-albeedos. The marketplace contains independent sellers who share one storefront.

ADR-0002 established the use of the existing `Tenant` model and terminology for the previous multi-tenant SaaS architecture. That decision was made in the context of an existing live SaaS database.

The current Vendor domain is being established as part of the greenfield marketplace design. The marketplace concept is a seller, not an isolated tenant or storefront.

## Decision

The new marketplace domain will use explicit `Vendor` terminology for the seller entity.

A Vendor has its own stable identity and lifecycle and exists independently of user accounts. Vendor-owned data will reference the Vendor explicitly where required.

The design will use `Vendor` consistently in new marketplace domain concepts, documentation, relationships, and application code.

## Why

Using `Vendor` reflects the actual business meaning of the entity and makes the marketplace architecture easier to understand.

Continuing to use `Tenant` would carry terminology from the retired multi-tenant SaaS model into the new marketplace model and could imply that each Vendor owns an isolated storefront.

The greenfield design does not have the historical database constraint that originally motivated ADR-0002.

## Consequences

### Positive

- The domain language matches the marketplace business model.
- Vendor ownership and isolation boundaries are explicit.
- New developers can distinguish Vendors from marketplace-wide shoppers and Platform Operators.
- The design avoids speculative multi-marketplace or multi-tenant abstractions.

### Negative

- Existing SaaS-era `Tenant` terminology may need to be removed or renamed where it is no longer applicable.
- Future migration work may require additional changes if existing SaaS structures are reused.

## Alternatives Considered

### Continue using Tenant

Rejected because `Tenant` represents an isolated SaaS store, while the current product is one shared marketplace containing multiple Vendors.

### Support both Tenant and Vendor

Rejected because it would introduce unnecessary complexity and two concepts for the same marketplace seller boundary.

## Scope

This decision applies to the new Vendor domain design. It does not implement database migrations, onboarding, authorization, payments, or other Vendor functionality.