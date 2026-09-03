# ADR-0004: One basket, one payment, one Order per vendor

**Status:** Accepted
**Date:** 2026-08-30

---

## Context

Today `Order` is per-tenant: a cart belongs to one tenant, checkout produces one order.
`CheckoutUseCase` wraps order creation + stock reservation + promotion usage + payment
intent in a single `DB::transaction`.

A marketplace cart holds items from multiple vendors. The shopper pays once. But each
vendor fulfils, ships, and is paid independently, and a refund against one vendor must not
touch another. We need an aggregate shape that supports:

- one payment authorisation for the basket total;
- independent per-vendor fulfilment lifecycles (the existing `Order` state machine);
- per-vendor shipping cost and address handling;
- per-vendor commission and Stripe transfer;
- per-vendor, per-line refunds.

## Decision

Introduce a thin parent, keep `Order` as the per-vendor unit.

```
OrderGroup                       (new — the shopper's basket-level record)
  id, user_id (nullable for guest), guest_email
  currency, base_currency, exchange_rate
  grand_total_cents              (sum of child order totals, at creation)
  payment_intent_id              (ONE Stripe PaymentIntent for the whole group)
  status: pending | paid | partially_refunded | refunded | cancelled | failed
  created_at
        │
        ├── Order  (existing model, now = one vendor's slice of the group)
        │     order_group_id (new FK), tenant_id (owning vendor)
        │     subtotal_cents, discount_cents, tax_cents, shipping_cents, total_cents
        │     commission_cents, commission_rate      (frozen at creation)
        │     stripe_transfer_id                     (set on payment success)
        │     status: existing per-vendor state machine (pending → paid → shipped → fulfilled …)
        │       └── OrderItem (unchanged — already snapshots price/tax/name/sku)
        │
        └── Order (vendor B) …
```

Rules:

1. **Checkout** groups cart items by vendor, creates one `OrderGroup` and N `Order`
   children in a single `DB::transaction`. Stock is reserved per line as today.
2. **Payment** is one `PaymentIntent` for `OrderGroup.grand_total_cents`. On
   `payment_intent.succeeded`: `OrderGroup → paid`, every child `Order → paid`, and one
   Stripe `Transfer` per child order is created (ADR-0005, pending). Transfer creation is
   idempotent and retryable.
3. **Fulfilment** is entirely per child `Order` — the existing state machine, guards, and
   events are unchanged. Vendors never see the `OrderGroup` or sibling orders.
4. **Refund** targets a child `Order` (or its lines). It refunds the shopper for that
   amount against the group's PaymentIntent and reverses that child's `Transfer`. Sibling
   orders are untouched. `OrderGroup.status` becomes `partially_refunded` / `refunded`
   derived from children.
5. **Order numbers:** `OrderGroup` gets a group reference shown to the shopper at
   checkout; each child `Order` keeps its own `order_number` (what the vendor and the
   tracking page use).
6. **Promotions / loyalty / referrals** apply at the `OrderGroup` level (marketplace-wide)
   and are allocated down to child orders pro-rata for commission and refund math.

## Alternatives considered

- **Keep a single `Order` spanning vendors, add `order_vendor_lines`.** Rejected: the
  existing state machine, guards, fulfilment, shipment, and refund code are all
  single-vendor; forcing multi-vendor into one `Order` rewrites all of it and every
  vendor query has to sub-filter line items.
- **No parent; N independent orders + a separate `payments` row linking them.** Rejected:
  something must own the single PaymentIntent, the group-level promo/loyalty allocation,
  and the shopper-facing "your order" view. That something is `OrderGroup`.
- **Separate PaymentIntent per vendor.** Rejected: the shopper would authorise N times / see
  N charges; defeats "pay once". Stripe Connect supports one charge → many transfers.

## Consequences

- **Easier:** vendor-side code (fulfilment, shipping, refund, earnings) barely changes —
  `Order` is still one vendor's order. Refund isolation between vendors is structural.
- **Harder:** checkout gets a grouping step; a new aggregate with its own status derivation;
  promo/loyalty allocation math; the shopper's "Orders" list now shows groups that expand
  into per-vendor shipments.
- **Migration:** new `order_groups` table + `order_group_id`, `commission_cents`,
  `commission_rate`, `stripe_transfer_id` on `orders`. Historical SaaS orders get a
  synthetic single-child `OrderGroup` backfill, or are left group-less with the UI
  handling `order_group_id IS NULL` as "legacy". Decide in
  [../marketplace-migration.md](../marketplace-migration.md).
- **Follow-up ADRs:** ADR-0005 Stripe Connect charge/transfer model; ADR-0006 commission
  configuration + freezing; promo/loyalty allocation note.
- **`INVARIANTS.md`** gains: "An OrderGroup has exactly one PaymentIntent for its total.
  A refund on one child Order never alters a sibling. commission_rate is frozen at Order
  creation."
