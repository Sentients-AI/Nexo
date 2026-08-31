# Marketplace Migration Plan

**Status:** Draft 2026-08-30
**Tracks:** the pivot from multi-tenant SaaS (isolated stores on subdomains) to a single
multi-vendor marketplace. See [../product/vision.md](../product/vision.md).

Related ADRs: [adr/0002](adr/0002-vendor-is-a-tenant-record.md) ·
[adr/0003](adr/0003-context-dependent-vendor-scoping.md) ·
[adr/0004](adr/0004-order-group-and-per-vendor-orders.md) · ADR-0005 (Stripe Connect, pending)

---

## Current state (2026-08-30)

- Live as a multi-tenant SaaS at `store.aljebal-albeedos.com`.
- The `redesign` branch has started the pivot: `add_marketplace_fields_to_tenants_table`
  migration, `StoreFollow` model, `Stores/Index.vue`, reworked `Home.vue`,
  `ResolveTenantFromSubdomain` changes, marketplace bottom-nav.
- No split checkout, no Stripe Connect, global `TenantScope` still active.

## Target state

| Dimension | From | To |
|-----------|------|----|
| Storefront | one isolated store per subdomain | one storefront, one domain |
| Tenant meaning | isolated store | vendor / seller (ADR-0002) |
| Isolation | blanket global scope | context-dependent explicit scope (ADR-0003) |
| Customer | `users.tenant_id`, per store | marketplace-wide account |
| Cart | single vendor | multi-vendor basket |
| Order | one per checkout | `OrderGroup` + one `Order` per vendor (ADR-0004) |
| Payment | direct PaymentIntent | one PaymentIntent per group → per-vendor transfers (ADR-0005) |
| Vendor money | paid directly | Stripe Connect, commission retained |
| Onboarding | instant self-serve trial | apply → operator approve → Connect onboarding |
| Admin | tenant landlord | marketplace operator (approvals, commission, payout reconcile) |

## Sequenced plan

Each phase is independently shippable and testable. Do not start a phase until the
previous one's tests are green.

### Phase A — Single domain + vendor identity (ADR-0002)

- Remove subdomain tenant resolution from the storefront; serve everything from one host.
- `CurrentVendor` resolved from the authenticated vendor-team user (not the URL).
- Relabel Filament `TenantResource` → "Vendors"; surface the new marketplace fields.
- Keep `tenants` table / `Tenant` model / `tenant_id` names.
- **Tests:** storefront reachable without a subdomain; vendor dashboard resolves the right
  vendor from the user.

### Phase B — Scoping flip (ADR-0003) — highest risk

- Ship together, in one change:
  1. remove global `TenantScope` from `BelongsToTenant`;
  2. add explicit `ScopedToCurrentVendor` enforced at the vendor base
     controller / policy layer;
  3. storefront queries updated to the shared catalog (governed by `is_active` +
     vendor `approved`);
  4. `tests/Feature/VendorScoping/` suite — cross-vendor read/write denial per endpoint —
     as a merge gate;
  5. architecture test: every `BelongsToTenant` model is on the storefront-public
     allow-list or only reached via the explicit vendor scope.
- **Do not merge** any part of Phase B without the full test suite.

### Phase C — Marketplace-wide customer

- Migration: `users.tenant_id` nullable; backfill shoppers to `NULL`, keep it set for
  vendor-team users.
- Auth: one shopper account across the marketplace; login not tied to a store.
- Loyalty / referral / wishlist / addresses rescoped to the user, not (user, vendor).
- **Tests:** a shopper who bought from vendor A and vendor B has one account, one loyalty
  balance, one order history.

### Phase D — Multi-vendor cart + split checkout (ADR-0004)

- Cart accepts items from multiple vendors.
- `OrderGroup` table + `order_group_id`, `commission_cents`, `commission_rate`,
  `stripe_transfer_id` on `orders`.
- `CheckoutUseCase` groups by vendor, creates group + child orders in one transaction.
- Promo / loyalty applied at group level, allocated pro-rata to children.
- Payment still direct (no Connect yet) — one PaymentIntent for the group total, no
  transfers. This lets checkout ship before Connect is wired.
- **Tests:** 2-vendor cart → 1 group + 2 orders + 1 PaymentIntent; stock reserved per line;
  totals reconcile.

### Phase E — Stripe Connect + commission (ADR-0005, ADR-0006 — pending)

- Vendor Connect (Express) onboarding link from the dashboard after approval.
- On `payment_intent.succeeded`: create one idempotent `Transfer` per child order,
  amount = child total − `commission_cents`.
- Commission = platform-wide % from config, frozen onto each `Order` at creation.
- Refund path reverses the relevant child `Transfer`.
- Operator: payout/transfer status view; reconcile against Stripe.
- **Tests:** payment success creates N transfers; refund reverses one transfer, siblings
  untouched; a vendor without completed Connect onboarding cannot be listed as sellable.

### Phase F — Onboarding + approval

- Public application form (replaces instant-trial `/start`).
- Operator approve / reject / suspend in Filament, audit-logged.
- Vendor sellable only when `approved` AND Connect onboarding complete.
- **Tests:** unapproved vendor's products never appear in the storefront; approval unlocks
  Connect link.

### Phase G — Redesign + RTL

- Tracked as its own epic; see [REDESIGN_BRIEF.md](../REDESIGN_BRIEF.md).
- Real RTL (logical properties), design tokens, component sheet.

## Data-migration decisions to make

- **Historical SaaS orders:** backfill a synthetic single-child `OrderGroup` per existing
  order, **or** leave them group-less and have the UI treat `order_group_id IS NULL` as
  "legacy order". → _decision pending; lean toward backfill for a uniform UI._
- **Existing tenants:** all become vendors with `approved = true` grandfathered; Connect
  onboarding required before their next payout.
- **Existing customers with `tenant_id`:** if a person shopped at two stores they may have
  two user rows today — dedupe by email into one marketplace account, or leave duplicates
  and merge on next login. → _decision pending; needs a data audit of the live DB first._

## Rollback posture

Phases A, C, D, F are ordinary migrations — reversible with a down migration and a deploy.
Phase B (scoping) is the one that cannot be half-rolled-back safely: if the explicit scope
has a hole, data leaks silently. Its rollback is "re-add the global scope", which is fast,
but the real safety is the merge-gate test suite. Phase E touches real money — test in
Stripe test mode against a staging DB that is **not** the production DB (fix the current
staging-shares-prod-DB setup first).
