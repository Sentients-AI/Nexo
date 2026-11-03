# ADR-0011: Model identity as one user table with vendor memberships and platform staff roles; enforce vendor isolation by context, policy and test

- **Number:** 0011
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner
- **Refines:** ADR-0003, which is still accepted: no global scope; explicit vendor scoping
- **Depends on:** ADR-0005 (Vendor naming; this ADR assumes it is accepted)

---

## Context

**Phase 0 finding S1 (critical).** In the old application, any registered shopper could reach `/vendor/*`. There were two causes:

- the vendor routes checked only `auth`;
- the tenant global scope failed open when no tenant was resolved.

ADR-0003 already chose explicit, context-dependent scoping over a global scope. `INVARIANTS.md` says:

- a vendor-team user belongs to exactly one vendor;
- an operator belongs to no vendor.

The brief (§15) requires authorisation to be tested at the application boundary.

## Problem

How are users, vendor teams and platform staff modelled? How is "this request acts for vendor X" established and enforced so that it cannot fail open?

## Decision drivers

- Default deny.
- A missing context must be an error, never "everything".
- Simple, explicit and auditable.
- No dependency unless it removes real work.

## Considered alternatives

### A. `spatie/laravel-permission` with database-stored roles and permissions

- Flexible, and familiar to reviewers.
- Rejected for v2: roles here are a fixed, small, code-reviewed set. Database-editable permissions add a privilege-escalation surface and test burden with no product need.

### B. Separate guards and user tables for customers, vendors and staff

Rejected. A vendor owner is also a person who may shop, and duplicate accounts confuse the model.

### C. One `users` table; `vendor_members` for vendor roles; `users.platform_role` for staff; permissions as PHP enums mapped from roles; Laravel policies (chosen)

## Decision

1. **One `users` table**, holding authentication and profile data. Every user can be a customer.
2. **Vendor membership** is `vendor_members(vendor_id, user_id UNIQUE, role, status)`.
   - In v2 a user belongs to **at most one vendor** (unique `user_id`).
   - Roles: `owner`, `manager`, `fulfilment`, `finance`, `support`.
   - Exactly one `owner` per vendor. This is enforced in the application, plus a unique key on `(vendor_id, owner_marker)` where `owner_marker` is 1 or NULL.
3. **Platform staff** are users with `platform_role ∈ {admin, operations, finance, support}`.
   - A staff user **may not** hold a vendor membership, and staff accounts cannot place orders. Both are enforced in the application and asserted by tests.
   - Staff accounts are created by an admin, never by registration. Two-factor authentication is mandatory for staff (Phase 3).
4. **Permissions** are an enum `Permission` (for example `vendor.orders.fulfil` or `platform.refunds.override`).
   - A static map in code assigns each role its permissions. The matrix is in `security-model.md`.
   - Policies check two things: (a) ownership, `resource.vendor_id === VendorContext.vendorId`, and (b) the role permission.
5. **VendorContext:**
   - The middleware `vendor.context` on every `/vendor/*` route resolves the active membership of the authenticated user.
   - It responds **403** if there is none, if the membership is not active, or if the vendor is not in an operating state for the action. Vendor state rules come from Vendors (`vendor-domain-design.md` §8).
   - The resolved context is an immutable value object injected into controllers and use cases.
   - There is **no** global Eloquent scope and **no** null-means-all fallback.
   - A vendor-scoped query without a context is a programming error. It throws `MissingVendorContext`.
6. **Vendor-owned queries:**
   - They use explicit `->whereBelongsToVendor($context)` query scopes, or receive `vendorId` as a required argument to an Application query.
   - Route-model binding for vendor routes is scoped. A vendor route binding `{order}` resolves only within the context's vendor; otherwise it returns 404, which avoids leaking existence.
7. **Platform admin** is Filament, behind a separate `/admin` panel. Access requires `platform_role`, and every Filament resource and action is checked by a policy.
8. **Impersonation:**
   - Only `admin` may impersonate, and never another staff member.
   - Each impersonation is audit-logged with start and stop.
   - Money-moving actions are blocked while impersonating.
9. **Isolation test suite** (`tests/Feature/Isolation/`). For every vendor-facing route and action, it asserts that:
   - vendor A's member cannot read or mutate vendor B's records (404 or 403);
   - a customer without membership gets 403;
   - a suspended membership gets 403.

   An arch test fails if a vendor controller is not covered by the dataset. The suite is a merge gate.

## Positive consequences

- Fails closed by construction.
- Authorisation is reviewable in one matrix and one place per resource.

## Negative consequences

- Multi-vendor membership (one person running two stores) is not supported in v2. A later ADR would add a context switcher.
- Role changes require a code change. That is intended.

## Risks

- A developer forgets the vendor scope in a new query. Mitigation: scoped route binding, required `vendorId` arguments, the isolation dataset arch check, and code review.

## Testing implications

- Isolation suite (merge gate).
- Policy unit tests per role × permission.
- Staff-cannot-order and staff-cannot-join-vendor tests.
- Impersonation audit tests.

## Migration implications

- `users` (from `main`) gains `platform_role`.
- New `vendor_members` table.

## Related

- ADR-0003, ADR-0005; `security-model.md`; Phase 0 S1.
