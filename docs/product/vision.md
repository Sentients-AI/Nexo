# Product Vision

**Status:** Ratified 2026-08-30 · Owner: Platform Operator
**Supersedes:** the "generic multi-tenant e-commerce SaaS" framing used in `README.md`
and `docs/COMPREHENSIVE_CODEBASE_GUIDE.md` prior to 2026-08-30.

---

## One sentence

**aljebal-albeedos (الجبل الأبيض) is a single Arabic-first online marketplace for the
Sudan market where many independent vendors sell through one storefront; customers shop
across vendors in one cart and pay once, and the platform handles payment, splits each
purchase into per-vendor orders, retains a commission, and settles vendors.**

## Names

| Thing | Name |
|-------|------|
| The product / marketplace | **aljebal-albeedos** |
| The engine / codebase | **Nexo** (internal; `APP_NAME`, package name, this repo) |

Nexo is infrastructure. There is exactly one marketplace running on it. If a second
marketplace is ever launched on the same engine, that is a new product decision recorded
in a new ADR — it is not the current goal and nothing should be built "to support many
marketplaces" speculatively.

## Why this exists

Sudan-market commerce has three structural gaps:

1. **Trust** — shoppers have no single reputable place to buy; storefronts are fragmented
   across social media and informal channels.
2. **Payments** — reliable online card payment is hard for an individual small vendor to
   set up and for a shopper to trust.
3. **Localisation** — most platforms treat Arabic/RTL and `SDG` as afterthoughts.

A curated marketplace closes all three at once: the shopper trusts one brand and one
checkout; the vendor gets reach and a payment rail they could not build alone; the
platform owns the localisation and the payment relationship.

## What success looks like

See [goals.md](goals.md) for measurable targets. In plain terms, V1 succeeds if:

- A shopper can discover products from several vendors, buy them in one transaction, and
  track each vendor's shipment.
- A vendor can onboard, get approved, list products, receive and fulfil orders, and be
  paid — without the platform operator touching the database.
- The platform operator can approve vendors, set the commission, and see that money moved
  correctly, from the admin panel alone.

## What this is NOT (V1)

- Not a hosted-storefront SaaS where each vendor gets an isolated site. That was the old
  model; it is being retired. See
  [../architecture/marketplace-migration.md](../architecture/marketplace-migration.md).
- Not a multi-marketplace / white-label platform.
- Not a logistics company — the platform does not move goods or run its own fleet in V1.
- Not a payout bank — Stripe holds balances and runs payouts; the platform does not
  operate a custom wallet or escrow ledger in V1.

## Guiding principles

1. **Pilot-sized.** Target is < 50 vendors and < 100 orders/day in year one. Optimise for
   correctness and speed of delivery, not throughput. No architecture whose only
   justification is scale we do not have.
2. **Money must feel safe, not exciting.** Checkout, payment-pending, refund, and earnings
   surfaces are calm, explicit, and reversible-looking. "Pending" never looks like
   "failed".
3. **Arabic is the primary language**, not a translation of English. RTL is real layout,
   not mirrored arrows.
4. **The domain layer is the asset.** The DDD structure, invariants, and audit trails
   built for the SaaS carry over. The pivot changes boundaries and money flow, not the
   engineering discipline.
5. **Decisions are written down.** Every boundary-shaping choice gets an ADR in
   [../architecture/adr/](../architecture/adr/).
