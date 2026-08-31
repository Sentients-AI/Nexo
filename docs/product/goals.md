# Goals & Non-Goals

**Status:** Ratified 2026-08-30

---

## V1 definition of success

V1 is done when all of the following are true without the platform operator touching the
database or the codebase:

1. A shopper discovers products from **at least two different vendors**, adds them to one
   cart, completes one Stripe payment, and sees **one order per vendor** with independent
   tracking.
2. A vendor applies, is approved by the operator, completes Stripe Connect onboarding,
   lists products, receives an order, marks it shipped with a tracking number, and Stripe
   records a transfer to that vendor for their portion minus commission.
3. A shopper requests a refund on one vendor's sub-order; a Finance user or the operator
   approves it; Stripe refunds the shopper and reverses that vendor's transfer; stock is
   released.
4. The operator sets the commission percentage from the admin panel and it applies to new
   orders only (historical orders keep their frozen rate).
5. Every screen in the shopper flow works at `/ar/...` (RTL) and `/en/...`.

## Scale assumptions (year one)

| Metric | Planning assumption |
|--------|---------------------|
| Vendors (approved, active) | < 50 |
| Orders / day | < 100 |
| Peak concurrent shoppers | Low tens |
| Catalog size | Low thousands of products |
| Database | Single MariaDB instance, no replica |
| Media | Local disk on the VPS |

**These numbers are a design constraint, not just a forecast.** Any proposal whose sole
justification is handling more than this is out of scope until the numbers change. When
they change, this file changes first, then the architecture.

## Measurable targets (revisit quarterly)

- Checkout p95 response time within the existing 3000 ms performance budget.
- Zero oversell incidents (enforced by pessimistic stock locking + torture tests).
- Zero cross-vendor data-leak findings in the dashboard scoping test suite.
- Vendor can go from "approved" to "first product live" in under 30 minutes unaided.

## Non-goals for V1

| Non-goal | Why deferred | Tracked in |
|----------|--------------|------------|
| Cash-on-delivery | Known gap for this market; needs a settlement model that isn't Stripe | [scope.md](scope.md) |
| Custom payout wallet / escrow UI | Stripe's default payout schedule is sufficient at pilot scale | [scope.md](scope.md) |
| Automated vendor KYC | Stripe Connect onboarding covers KYC | [scope.md](scope.md) |
| Local carrier / label integration | Vendors self-manage carriers in V1 | [scope.md](scope.md) |
| Multi-marketplace / white-label | Not a product goal | [vision.md](vision.md) |
| Designing for > pilot scale | See scale assumptions above | this file |
| Subscriptions, gift cards, AI recs, real-time chat, Q&A, promotion experiments | Built for the SaaS; not core to the marketplace V1; keep dormant or remove | [scope.md](scope.md) |
