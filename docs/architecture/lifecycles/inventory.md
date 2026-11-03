# Inventory Lifecycle

- **Status:** Draft for owner review (Phase 1). It implements ADR-0014.
- **Date:** 2026-11-01

## 1. Quantities

```
on_hand   = units physically held by the vendor (Σ movements.delta_on_hand)
reserved  = units held for unpaid OrderGroups (Σ active reservation qty)
available = on_hand − reserved            (never stored, never negative)
```

## 2. Reservation state machine

```
           reserve (checkout TX)
               │
               ▼
            active ──── payment captured ───────► consumed      (on_hand −q, reserved −q, movement: sale)
               │  └──── group expired (sweeper) ─► expired      (reserved −q, movement: release)
               └─────── group cancelled/failed ──► released     (reserved −q, movement: release)
   extension: active.expires_at += ≤ 15 min, once, if payment processing (extended_at set)
```

**Guard.** Every transition is `UPDATE stock_reservations SET status = :to WHERE id = :id AND status = 'active'`. Only when that update affects 1 row are the `stock_levels` change and the movement applied, in the same transaction.

## 3. Movement types

| Type | Δ on_hand | Δ reserved | Reference |
|---|---|---|---|
| `receipt` | +q | 0 | adjustment id |
| `adjustment` (damage, correction, with reason) | ±q | 0 | adjustment id |
| `count` (absolute set) | target − current | 0 | adjustment id |
| `reservation` | 0 | +q | reservation id |
| `release` | 0 | −q | reservation id |
| `sale` | −q | −q | reservation id |
| `cancellation_restock` | +q | 0 | vendor order line id |
| `return_restock` | +q (resellable qty) | 0 | return request line id |

UNIQUE (type, reference_type, reference_id, variant_id) makes each logical change apply at most once.

## 4. Scenario matrix (brief §10 and §11, inventory)

| # | Scenario | Expected outcome | Mechanism | Test |
|---|---|---|---|---|
| I1 | Insufficient stock | Checkout fails as a whole; short lines named; nothing reserved | Conditional update affects 0 rows → rollback | F |
| I2 | Two customers buy the last unit concurrently | Exactly one OrderGroup created; the other gets I1 | Conditional update (row lock in InnoDB) | C (N=20 parallel processes) |
| I3 | Concurrent reservations over overlapping variants | All complete or fail cleanly; no deadlock surfaces to the user | Ascending `variant_id` lock order; one automatic retry on deadlock (1213) | C |
| I4 | Reservation expires | Released by the sweeper; stock available again; group `expired`; promo and points released | Ordering `ExpireUnpaidGroups` | F (frozen clock) |
| I5 | Payment fails after reservation | Reservation stays active until TTL so the customer can retry; then I4 | — | F |
| I6 | Reservation release fails (crash mid-release) | The transaction rolls back; the reservation is still `active`; the next sweeper run releases it | Idempotent sweeper | F |
| I7 | Inventory adjustment conflicts (two members count stock simultaneously) | The first succeeds; the second gets 409 with the current value | `version` compare-and-swap | C |
| I8 | Vendor reduces on-hand below reserved | Rejected with "N units are reserved by unpaid checkouts" | Guarded update `on_hand + d >= reserved` | F |
| I9 | Vendor cancels an order | Before payment: N/A (vendors never see unpaid orders). After payment, before shipping: `cancellation_restock` +q; refund triggered | VendorOrder cancel use case | F |
| I10 | Stock movement duplicated (event replay, double job) | The second insert violates the unique key → no-op; quantities unchanged | Unique movement key + reservation status guard | F, C |
| I11 | Payment succeeds after the reservation expired and stock was resold | Late-success path (checkout P4): re-reserve fails → affected VendorOrder cancelled and refunded | ADR-0015 §7 | F |
| I12 | Return received | `return_restock` only for resellable qty | Vendor action | F |
| I13 | Reconciliation | Nightly: `reserved = Σ active`, `on_hand = Σ movements`; a mismatch raises an exception (never auto-fixed) | Reconciliation job | F |

## 5. What vendors see

- **Per variant:** on-hand, reserved ("held in unpaid checkouts"), available, low-stock badge.
- **Movement history**, filterable by type, with links to orders and returns.
- **Conflict messages** name the current values. They never show a bare "error".
