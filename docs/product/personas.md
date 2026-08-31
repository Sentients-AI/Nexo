# Personas

**Status:** Ratified 2026-08-30

Four primary personas. The vendor-team sub-roles already exist in code
(`app/Domain/Role`) and carry over from the retired SaaS model as **vendor-scoped team
roles**.

---

## 1. Shopper (and Guest)

**Who:** A Sudan-market consumer. Mobile-first. Arabic-primary. May check out as a guest
and create an account afterwards.

**Goals**
- Find products across many vendors without visiting many sites.
- Buy from several vendors in one transaction, one payment.
- Trust that payment is safe and that a bad order can be refunded.
- Track each vendor's shipment for a multi-vendor order.

**Needs from the system**
- Shared catalog + cross-vendor search + category browsing + vendor store pages.
- One cart holding items from multiple vendors.
- One calm, explicit checkout; clear "pending" vs "failed" states.
- Order history, per-shipment tracking, refund/return requests.
- Wishlist, loyalty points, referral codes (loyalty/referrals are marketplace-wide, not
  per-vendor).

**Does not need / must not have**
- Any view into vendor operations, other shoppers' data, or platform internals.

---

## 2. Vendor Owner

**Who:** A small merchant or SME selling on the marketplace. Signs the commission
agreement. Completes Stripe Connect onboarding.

**Goals**
- Get listed and start selling quickly.
- Receive orders, fulfil them, get paid reliably.
- Understand what sells and what earnings/payouts look like.

**Needs from the system**
- Application + approval flow; Stripe Connect onboarding link.
- Product + variant + stock management; bulk CSV import.
- Order queue scoped to their own sub-orders; fulfilment + tracking entry.
- Earnings view (commission retained, transfer status) — read-only, sourced from Stripe.
- Store page branding; shipping methods/rates; team management.

**Boundary**
- Sees only their own products, sub-orders, customers-who-bought-from-them, earnings.
- Cannot see other vendors, platform-wide figures, or the shopper's full basket (only
  their portion of it).

---

## 3. Vendor Team member — Manager / Support / Warehouse / Finance

**Who:** Staff the Vendor Owner adds to their store. Roles already modelled.

| Role | Can do | Cannot do |
|------|--------|-----------|
| **Manager** | Catalog, inventory, orders, promotions | Billing, team, Connect settings |
| **Support** | Customer chat, Q&A, review replies, order lookup | Change catalog or prices |
| **Warehouse** | Fulfilment queue, mark packed/shipped, stock adjustments, tracking numbers | Pricing, refunds, settings |
| **Finance** | Refund approval, earnings/payout views | Catalog, fulfilment |

All vendor-team roles are scoped to that one vendor.

---

## 4. Platform Operator (Super Admin)

**Who:** The aljebal-albeedos team. Runs the marketplace.

**Goals**
- Onboard trustworthy vendors; keep bad actors out.
- Ensure money moved correctly on every order.
- Keep the catalog coherent and the platform healthy.

**Needs from the system** (Filament control plane)
- Vendor applications: review, approve, suspend.
- Commission configuration.
- Cross-vendor order, revenue, and payout visibility; reconcile against Stripe.
- Fraud / anomaly review, audit log, system health.
- CRUD over every domain resource for support and correction.
- Vendor impersonation for support.

**Boundary**
- Full read across the marketplace. Writes that move money or change vendor state are
  audit-logged.

---

## Identity rules (consequences for the data model)

- A **shopper** is marketplace-wide. Not owned by any vendor. (`users.tenant_id` is
  removed / nulled for shoppers — see
  [../architecture/marketplace-migration.md](../architecture/marketplace-migration.md).)
- A **vendor-team user** belongs to exactly one vendor.
- A **platform operator** belongs to no vendor.
