# Orders, Fulfilment, Returns and Refunds Lifecycle

- **Status:** Draft for owner review (Phase 1). It implements ADR-0012, ADR-0015 §9 and ADR-0016.
- **Date:** 2026-11-01

## 1. VendorOrder

```
pending_payment ──(group paid)──► awaiting_fulfilment ──► processing ──► shipped ──► delivered ──► completed
      │                                │                    │                          │
      └─(group expired/cancelled)─► voided                  │                          └─ return window (7 d) elapsed,
                                       └────► cancelled ◄───┘                             no open return/dispute
```

| Transition | Actor | Preconditions | Side effects (same TX unless noted) |
|---|---|---|---|
| → `awaiting_fulfilment` | System | Group paid | Stock consumed; `refundable_cap_minor` set; outbox `VendorOrderPaid` |
| → `voided` | System | Group expired or cancelled | Reservations released |
| → `processing` | Vendor (`orders.fulfil`) | — | History |
| → `shipped` | Vendor (`orders.fulfil`) | Carrier + tracking | Shipment row; outbox (customer notified); `delivered` auto-deadline = +10 d |
| → `delivered` | Vendor, customer, or system at the deadline | — | `return_window_ends_at` = +7 d |
| → `completed` | System (hourly) | Window ended; no open return; no open dispute allocation | Outbox `VendorOrderCompleted` → Finance maturity, Loyalty award, review prompt |
| → `cancelled` | Customer (from `awaiting_fulfilment` only), vendor (`orders.cancel`, before `shipped`), platform Operations | Reason required | `cancellation_restock` for consumed stock; full refund of the VendorOrder (goods + shipping, net of allocations) via Payments; outbox |

**Concurrency rule.** Each transition is `UPDATE … WHERE id = ? AND status = :from`. The loser gets 409 `order.transition_conflict` with the current status and the actor who changed it.

## 2. OrderGroup (customer view, derived)

| Derived label | Rule |
|---|---|
| Processing | Any VendorOrder in `awaiting_fulfilment`/`processing` |
| Partially shipped | Some shipped or delivered, some not |
| Delivered | All non-cancelled VendorOrders delivered or completed |
| Partially refunded / Refunded | Σ succeeded refunds > 0 / = Σ captured |
| Cancelled | All VendorOrders cancelled |

## 3. ReturnRequest

```
requested ──(vendor approves)──► approved ──(vendor marks received)──► received ──(refund succeeded)──► closed
    │   └──(vendor rejects)──► rejected ──(customer escalates ≤ 3 d)──► escalated
    └──(no vendor response 3 d)──────────────────────────────────────► escalated
escalated ──(Operations upholds rejection)──► rejected (final)
escalated ──(Operations overrides)──► approved
```

| Rule | Detail |
|---|---|
| Eligibility | VendorOrder `delivered`; now ≤ `return_window_ends_at`; qty ≤ purchased − already returned |
| Effect on completion | An open return blocks `completed` (and so blocks payable maturity) |
| Receipt | `received_qty`, `resellable_qty` per line → `return_restock` for resellable units; refund created for received units |
| Not-received claim (S) | Shipped past deadline + 5 d: the customer opens a claim; Operations decides; refund without restock |

## 4. Refund

```
requested ──► approved ──► submitted ──► succeeded
                               └───────► failed  (refund_committed decremented; Finance queue)
```

| Initiator | Starts at | Approval |
|---|---|---|
| System (cancellation, return received, stock unavailable after payment) | `approved` | Implicit |
| Vendor Owner/Finance goodwill | `approved` | Vendor's own decision; capped at the refundable remainder |
| Platform Finance override | `approved` | Staff decision; audit |
| (Customer) | — | Customers never create refunds directly; they create returns or cancellations |

`requested` exists for future approval workflows. In v2 all initiators are pre-authorised, so refunds start at `approved`.

**Amount computation** (per line, from stored allocations):

- **Full line:** goods − allocated discount − allocated points value. Shipping is added only when the refund makes the VendorOrder fully refunded.
- **Partial quantity:** that share of each stored allocation, using largest remainder across the units.
- **Goodwill amount:** pro-rata reversal of commission, discount and points against the line's paid amount (financial-model §4.7).

## 5. Scenario matrix (orders and refunds)

| # | Scenario | Expected outcome | Test |
|---|---|---|---|
| O1 | Two members transition the same VendorOrder at once | One succeeds; the other gets 409 | C |
| O2 | Customer cancels while the vendor moves it to processing | Exactly one wins; if processing wins, the customer sees "being prepared; use returns" | C |
| O3 | Vendor cancels one of three VendorOrders | Refund for that VendorOrder only; siblings' state and ledger unchanged (INV-4) | F |
| O4 | Return of 1 of 2 units | Refund = one unit's share of stored allocations; commission reversed exactly | F |
| O5 | Two refund requests race on the last refundable amount | One commits; the other fails `refund.exceeds_refundable` (INV-5) | C |
| O6 | Refund after the VendorOrder completed and was paid out | Vendor debit to receivable; netted at the next payout (financial-model §4.7) | F |
| O7 | Refund requested while a dispute is open | Rejected `refund.dispute_open` | F |
| O8 | Return open when the window ends | `completed` waits until the return closes | F |
| O9 | Auto-deliver and auto-complete jobs run twice | Idempotent: guarded transitions | F |
| O10 | Vendor suspended with open orders | Can still fulfil, cancel and refund existing orders; receives no new ones | F |
