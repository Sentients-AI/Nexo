# ADR-0014: Track inventory as on-hand and reserved quantities per variant, with TTL reservations and an idempotent movement journal

- **Number:** 0014
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

Phase 0 finding C3 (critical). The old inventory code:

- double-subtracted stock on reservation (`quantity` decremented **and** `reserved` incremented, while availability was computed as `quantity − reserved`);
- ignored the variant on release;
- never expired reservations.

Its "concurrency" tests ran on SQLite and passed anyway.

The brief requires correct behaviour (§10, §11) for:

- the last unit;
- concurrent reservation;
- reservation expiry;
- payment failing after reservation;
- reservation release failing;
- conflicting adjustments;
- vendor cancellation;
- duplicated stock movements.

## Problem

What is the inventory data model, and which concurrency mechanism makes overselling impossible?

## Decision drivers

- No overselling under concurrency, provable on MySQL.
- Every quantity change is explained by a journal entry.
- Recovery from crashes without manual repair.

## Considered alternatives

### A. Decrement stock at add-to-cart

Rejected. Abandoned carts lock up inventory.

### B. Decrement stock only when payment succeeds (no reservation)

Rejected. Two customers can both pay for the last unit, and one must then be refunded. That is poor UX and makes the "last unit" scenario a refund scenario.

### C. Pessimistic `SELECT … FOR UPDATE` on `stock_levels`, then write

Correct, but every check needs a lock round-trip. Deadlock risk if locks are taken in inconsistent order.

### D. Atomic conditional `UPDATE … WHERE available >= qty`, with rows touched in ascending `variant_id` order (chosen)

One statement per line, correct under concurrency in InnoDB, with deadlocks minimised by ordering. Used inside the checkout transaction.

### E. Optimistic version column for all writes

Kept only for *absolute* vendor stock sets (D handles deltas better).

## Decision

1. **`stock_levels`** has columns `variant_id` (PK), `vendor_id`, `on_hand INT UNSIGNED`, `reserved INT UNSIGNED`, `version INT UNSIGNED`.
   - DB `CHECK (reserved <= on_hand)` is enforced on MySQL 8.0.16+.
   - `available = on_hand − reserved` is never stored.
2. **Reserve** (inside the checkout transaction, lines sorted by `variant_id`):

   ```sql
   UPDATE stock_levels SET reserved = reserved + :qty, version = version + 1
    WHERE variant_id = :v AND on_hand - reserved >= :qty
   ```

   - 0 rows means insufficient stock. The whole checkout transaction rolls back and the customer sees which lines are short.
   - A `stock_reservations` row is created with `(order_group_id, variant_id, qty, status = active, expires_at = now + TTL)`.
   - **TTL default: 15 minutes.**
3. **Consume** (payment captured): a reservation goes `active → consumed`, guarded by `WHERE status = 'active'`. In the same statement set, `on_hand -= qty` and `reserved -= qty`.
4. **Release** (payment failed terminally, group expired or cancelled, or a cancellation before shipping): a reservation goes `active → released | expired`, and `reserved -= qty`.
5. **Stock that is already consumed** (cancellation after payment, before shipping) is returned with `on_hand += qty` and a `cancellation_restock` movement.
6. **Returns:** `on_hand += qty` only when the vendor marks a returned item `received` *and* `resellable`.
7. **Every quantity change** writes a `stock_movements` row: `(variant_id, type, delta_on_hand, delta_reserved, reference_type, reference_id, actor)`.
   - Unique key `(type, reference_type, reference_id, variant_id)`.
   - A duplicated release or consume attempt hits the unique key and is a no-op. This covers the "stock movement is duplicated" scenario.
   - The guarded reservation status update is the primary protection; the unique key is defence in depth.
8. **Vendor adjustments:**
   - *Delta* ("received 20 units"): `on_hand = on_hand + :d WHERE on_hand + :d >= reserved`.
   - *Absolute set* (stock count): compare-and-swap on `version`. On mismatch, return 409 "stock changed since you loaded it", showing the current value.
   - Setting on-hand below `reserved` is rejected, with a message naming the reserved quantity.
9. **Sweeper.** It is owned by **Ordering** as `ExpireUnpaidGroups`, scheduled every minute, because Inventory must not depend on Payments (`dependency-rules.md`). It handles OrderGroups in `pending_payment` whose reservations are past `expires_at`. For each group it first asks Payments for the attempt state (ADR-0015):
   - if the payment is `processing` or `requires_action`, it extends the reservation once, by up to 15 minutes;
   - otherwise it expires the group, releasing the reservations and cancelling the provider intent.

   The sweeper is idempotent and safe to run concurrently (`FOR UPDATE SKIP LOCKED` on candidate groups).
10. **Reconciliation invariant** (nightly and in tests): for each variant, `reserved = Σ active reservations.qty`, and `on_hand = Σ movements.delta_on_hand`. A mismatch raises an operational alert, not a silent fix.

## Positive consequences

- Overselling is impossible by construction, and this is provable with parallel-process tests.
- Full audit trail.
- Crash-safe through guarded updates and the sweeper.

## Negative consequences

- Reserved stock is unavailable for up to the TTL, plus at most one extension.
- The sweeper is an operational dependency (the scheduler must run).

## Risks

- **Late payment after expiry.** The customer paid but the reservation expired and the stock was sold elsewhere. Handled in ADR-0015: re-reserve, or automatically refund the affected VendorOrder.
- **Scheduler outage.** Reservations persist longer, so nothing is oversold, and a health check alerts.

## Testing implications

On MySQL with parallel processes (ADR-0009):

- N buyers for 1 unit → exactly 1 succeeds;
- concurrent reservations across overlapping variants complete without deadlock failures (or retry on deadlock);
- duplicate consume/release is a no-op;
- an absolute set races a reservation;
- the sweeper races a webhook;
- the reconciliation invariant holds after random operation sequences.

## Migration implications

None (greenfield).

## Related

- ADR-0015; `lifecycles/inventory.md`; Phase 0 C3.
