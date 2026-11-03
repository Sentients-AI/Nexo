# ADR-0012: Represent a checkout as an OrderGroup of VendorOrders with explicit state machines

- **Number:** 0012
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner
- **Supersedes (on acceptance):** ADR-0004. Its structure is kept. Its Stripe Connect transfer rules and its "one PaymentIntent" invariant are replaced.

---

## Context

ADR-0004 chose one parent (`OrderGroup`) with one `Order` per vendor. That structure satisfies the brief (§8). Two of its rules conflict with later decisions:

- **Transfers:** a Stripe Connect `Transfer` per child on payment, and a transfer reversal per refund. D-6 replaces this with an internal ledger and simulated payouts (ADR-0016).
- **PaymentIntent:** "exactly one PaymentIntent per OrderGroup". With checkout retries after a decline or provider outage, a group can have several *attempts* over time. What matters is *at most one active attempt* and *at most one successful capture*.

Phase 0 (C4) also found the old state machine bypassed: `updateStatus` accepted any status.

## Problem

What is the order aggregate shape? Which statuses and transitions exist, and who may perform each?

## Decision drivers

- Vendor fulfilment and refunds are independent.
- The customer sees one purchase.
- Transitions are guarded, concurrency-safe and auditable.

## Considered alternatives

### A. A single Order with vendor-tagged lines

Rejected for the reasons in ADR-0004.

### B. Keep ADR-0004 verbatim

Rejected because of the two conflicts above.

### C. OrderGroup + VendorOrder, with the payment and ledger concerns moved out (chosen)

## Decision

1. **Names:**
   - `OrderGroup` is the customer's purchase. It is called the "order" in customer UI, with reference `NX-YYMMDD-XXXXXX`.
   - `VendorOrder` is one vendor's portion, with reference `<group ref>-<n>`, plus its `VendorOrderLine`s.
   - `ADR-0004`'s `Order` becomes `VendorOrder`, to avoid ambiguity.
2. **OrderGroup.status** (stored):

   ```
   pending_payment → paid
   pending_payment → expired     (reservation TTL elapsed; system)
   pending_payment → cancelled   (customer abandons before paying)
   ```

   Refund and fulfilment summaries are **derived** from the VendorOrders, never stored on the group.

3. **VendorOrder.status** (stored):

   ```
   pending_payment ─► awaiting_fulfilment ─► processing ─► shipped ─► delivered ─► completed
          │                   │                  │
          ▼                   ▼                  ▼
        voided           cancelled           cancelled
   ```

   | Transition | Who | Guard |
   |---|---|---|
   | `pending_payment → awaiting_fulfilment` | System (payment captured) | Group paid |
   | `pending_payment → voided` | System (group expired or cancelled) | — |
   | `awaiting_fulfilment → processing` | Vendor (`fulfilment`, `manager`, `owner`) | — |
   | `processing → shipped` | Vendor | Carrier + tracking number required |
   | `shipped → delivered` | Vendor, customer confirmation, or system after N days (no carrier integration) | — |
   | `delivered → completed` | System | Return window (default 7 days) elapsed and no open return or dispute |
   | `awaiting_fulfilment / processing → cancelled` | Vendor (`manager`, `owner`) with reason; customer (only from `awaiting_fulfilment`); platform `operations` | Triggers a full refund of that VendorOrder and release of stock |

4. **Returns** are a separate `ReturnRequest` per VendorOrder:

   ```
   requested → approved → received → closed
   requested → rejected
   ```

   - Platform `operations` may override a vendor rejection.
   - Refunds are separate Payments objects (ADR-0015, ADR-0016). A VendorOrder's refund state (`none | partial | full`) is derived.

5. **Every transition** is a use case that:
   - executes `UPDATE vendor_orders SET status = :to, … WHERE id = :id AND status = :from`, and treats 0 affected rows as a conflict (HTTP 409, or a no-op for idempotent system retries);
   - writes `vendor_order_status_history` (from, to, actor, reason, timestamp);
   - writes an outbox event in the same transaction.

   Controllers never set `status` directly. An arch test forbids status assignment outside the state-machine class.

6. **Snapshots.** Lines snapshot product name, variant options, SKU, unit price, quantity, and the allocated discount and points. The VendorOrder snapshots the commission rate in basis points, the shipping amount and the shipping address. Later catalogue or price changes never alter an order.

7. **Vendors see** only their VendorOrder, its lines, the shipping address and the customer's display name and contact needed for fulfilment. They never see the group total or sibling orders.

## Positive consequences

- Refund and fulfilment isolation between vendors is structural.
- Transitions are race-safe and auditable.

## Negative consequences

- Derived group status needs a query or projection for customer order lists.
- Two references per purchase.

## Risks

- `delivered` without carrier integration is a trust assumption. Mitigation: auto-deliver N days after shipping (configurable). Customers can open a "not received" return.

## Testing implications

- State-machine unit tests over every from/to pair: allowed and rejected.
- Concurrency test: two simultaneous transitions; exactly one succeeds.
- Snapshot-immutability tests.
- Vendor visibility tests (no sibling data).

## Migration implications

- ADR-0004 → Superseded by ADR-0012.
- `INVARIANTS.md` "Payment Ownership" is reworded.

## Related

- ADR-0004, ADR-0015, ADR-0016; `lifecycles/orders-fulfilment-and-returns.md`.
