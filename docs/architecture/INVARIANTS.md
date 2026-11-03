# System Invariants

- **Status:** Revised 2026-11-01 (Phase 1 draft for owner review). This replaces the 2026-08-30 list. Every earlier invariant is kept, reworded or strengthened; the changes are listed at the end.

These properties must hold regardless of implementation. Each one names its **enforcement**, meaning the mechanism that makes a violation impossible or detectable, and its **proof**, meaning the test or check that demonstrates it. An invariant without both is a wish, not an invariant.

Requirement documents cite these as **[INV-n]**.

---

## Access and identity

**INV-1 · Vendor isolation.** A vendor context can read or change only records owned by its vendor. Storefront catalogue queries are intentionally not vendor-scoped.

- **Enforcement:** the `vendor.context` middleware fails closed; there is no global scope; route bindings are scoped; vendor queries require an explicit `vendorId`.
- **Proof:** the isolation suite (merge gate), plus an arch test that every vendor controller is covered (ADR-0011).

**INV-2 · Identity.**

- A customer identity is marketplace-wide.
- A user is a member of at most one vendor.
- Platform staff belong to no vendor and place no orders.
- **Enforcement:** unique `vendor_members.user_id`, and application guards.
- **Proof:** domain tests.

## Orders

**INV-3 · Order structure.**

- An OrderGroup is one customer checkout.
- A VendorOrder is exactly one vendor's portion, and all of its lines belong to that vendor.
- The group total equals the sum of its VendorOrder totals at creation.
- **Enforcement:** the checkout use case, plus a DB foreign key from line → variant → vendor that is checked at creation.
- **Proof:** property test over random carts.

**INV-4 · Refund isolation.** A refund, cancellation or dispute outcome on one VendorOrder never changes the state or ledger entries of a sibling VendorOrder.

- **Proof:** sibling-unchanged assertions in every refund and cancellation test.

**INV-5 · Refund cap.** For each VendorOrder, the sum of refunds in `requested | approved | submitted | succeeded` never exceeds the captured amount allocated to it. Per line, refunds never exceed the line's net amount.

- **Enforcement:** guarded counter updates on `vendor_orders.refund_committed_minor` and `vendor_order_lines.refund_committed_qty` (ADR-0015 §9).
- **Proof:** concurrent refund test on MySQL.

**INV-6 · Snapshots and frozen rates.** Line prices, names and options, allocated discounts and points, shipping amounts, and the commission rate are fixed when a VendorOrder is created. Later catalogue, price or commission-configuration changes never alter existing orders.

- **Proof:** mutation-after-order tests.

**INV-14 · Guarded transitions.** OrderGroup, VendorOrder, ReturnRequest, Refund, PaymentAttempt, Reservation and Vendor statuses change only through their state machine. Each change uses a conditional update on the current status, so concurrent transitions cannot both succeed.

- **Proof:** an arch test (no status assignment outside state machines), plus concurrent transition tests.

## Inventory

**INV-7 · Stock.** For every variant:

- `0 ≤ reserved ≤ on_hand`;
- `reserved = Σ qty of active reservations`;
- `on_hand = Σ delta_on_hand of movements`.

Stock is never oversold.

- **Enforcement:** DB CHECK constraint, conditional updates, and the unique movement key.
- **Proof:** parallel-process tests and the nightly reconciliation check (ADR-0014).

## Money

**INV-8 · Exact allocation.** Every split (discounts, points value, commission, dispute amounts) sums exactly to its source amount, in integer minor units.

- **Proof:** property tests (ADR-0013).

**INV-9 · Idempotency.**

- One OrderGroup per (customer, checkout key).
- One effect per provider event id.
- At most one active payment attempt per group.
- At most one succeeded attempt per group.
- At most one ledger transaction per (type, reference).
- At most one consumption per inbox (consumer, message).
- **Enforcement:** unique keys.
- **Proof:** concurrent duplicate tests.

**INV-10 · Ledger.**

- Every ledger transaction balances (Σ debits = Σ credits, single currency).
- Entries are append-only.
- Balances are derived from entries.
- **Proof:** the posting-service assertion, the nightly check, and property tests (ADR-0016).

**INV-11 · Paid means captured.** An OrderGroup is `paid` if and only if exactly one PaymentAttempt has `succeeded`, with amount and currency equal to the group total. A provider event with a mismatched amount never marks a group paid.

- **Proof:** webhook processing tests plus nightly reconciliation.

**INV-16 · Payout safety.**

- A payout never exceeds the vendor's available balance net of receivable.
- Held funds are never paid out.
- Each payout run pays each vendor at most once.
- **Proof:** concurrent payout-run test.

**INV-17 · Points.**

- A customer's spendable points balance equals the sum of their points-ledger entries and is never negative.
- Redemptions are held, then confirmed or released, exactly once.

**INV-18 · Promotion limits.** Confirmed plus pending redemptions never exceed a promotion's total or per-customer limit.

- **Enforcement:** a conditional update on the usage counter.
- **Proof:** parallel test on the last allowed use.

## Catalogue and vendors

**INV-12 · Sellability.** A product is visible in the storefront and purchasable only if all of these hold:

- product `active`;
- not blocked;
- vendor `approved`;
- at least one active variant.

One implementation is used by the storefront, search indexing and checkout.

- **Proof:** a sellability matrix test across all three paths.

**INV-13 · Vendor lifecycle.**

- Vendor status is always one of `pending | approved | suspended`, and changes only through defined transitions.
- Vendors are never physically deleted.
- **Enforcement:** DB CHECK constraint, the state machine, and `RESTRICT` foreign keys.

## Platform

**INV-15 · Events if and only if commit.** An integration event is published if and only if the transaction that produced it commits. Consumers tolerate duplicates and reordering.

- **Enforcement:** the outbox and inbox (ADR-0017).
- **Proof:** rollback and duplicate tests.

**INV-19 · Auditability.** These actions write an audit record (actor, impersonator if any, action, subject, before/after, IP, timestamp) in the same transaction as the change:

- money-moving actions;
- vendor lifecycle changes;
- moderation;
- commission changes;
- staff changes;
- reconciliation resolutions;
- impersonation.

**INV-20 · Environment isolation.**

- No Nexo 2.0 environment holds legacy production credentials.
- No environment accepts live payment keys.
- Tests run only against databases whose names end in `_testing`.
- **Enforcement:** boot checks and the test bootstrap guard (ADR-0009, ADR-0021).

**INV-21 · Documentation.** Boundary-shaping decisions have an ADR. Scope changes update the product documents in the same change.

---

## Changes from the 2026-08-30 version

| Earlier invariant | Now |
|---|---|
| Vendor Isolation | INV-1, with enforcement made explicit |
| Marketplace Identity | INV-2, adding "staff place no orders" |
| Order Structure | INV-3 |
| Payment Ownership ("exactly one PaymentIntent for its total") | INV-9 / INV-11: retries can create several attempts over time; at most one active and at most one succeeded |
| Refund Isolation | INV-4, plus the new refund cap INV-5 |
| Commission (frozen at creation) | INV-6, extended to every snapshot |
| Inventory | INV-7, made quantitative |
| Vendor Visibility ("approval and payment-onboarding conditions") | INV-12; payment onboarding removed (no Stripe Connect, D-6) |
| Auditability | INV-19, with an explicit list |
| Documentation | INV-21 |
| — | New: INV-8, 10, 13–18, 20 |
