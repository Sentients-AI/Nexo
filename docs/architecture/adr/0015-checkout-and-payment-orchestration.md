# ADR-0015: Orchestrate checkout as a short saga with provider calls outside database transactions, idempotency keys at every boundary, and webhook-driven payment state

- **Number:** 0015
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

Phase 0 findings:

- **C1 (critical):** successful payments never marked orders paid. The webhook handler re-confirmed an already-succeeded intent, and the `PaymentSucceeded` event was never dispatched.
- **C6 (high):** the Stripe call ran inside the database transaction.
- **C5 (high):** refunds raced and had no provider idempotency keys.
- A guest could check out another session's cart.

The brief's payment scenarios (§11) are:

- success, decline and pending;
- late, duplicated and forged webhooks;
- browser closed;
- checkout retry;
- partial refund success;
- provider outage;
- crash mid-processing.

## Problem

What are the transaction boundaries, sources of truth and idempotency mechanisms for checkout, payment and refund?

## Decision drivers

- Never hold database locks across a network call.
- The provider's signed event is the source of truth for money movement. The browser never is.
- Every retry, by user, queue or webhook, is safe.

## Considered alternatives

### A. A single transaction including the provider call (old design)

Rejected. It holds locks during network I/O, and a rollback can leave an orphaned provider intent.

### B. Redirect-based confirmation (trust the return URL)

Rejected. A closed browser loses the order, and the return URL is client-controlled.

### C. Saga: local transaction → provider call after commit → webhook-driven completion, with sweepers (chosen)

## Decision

1. **Checkout requires an authenticated customer.** Guest checkout is out of scope for v2 (PRD §6); the cart is merged on login. This removes the guest-cart ownership class of bug.

2. **Step 1, place.** `POST /checkout` carries an `Idempotency-Key`: a UUID generated when the checkout page renders, unique per customer.
   1. Re-price the cart server-side: current prices, promotions, points, shipping quotes. If the total differs from the total the customer confirmed, return **409** with the new summary. Never charge an amount the customer did not see.
   2. In **one DB transaction**, create the following, then commit:
      - `OrderGroup(pending_payment)`, its `VendorOrder`s and lines (snapshots);
      - the reservations (ADR-0014);
      - the promotion redemption and points hold, both in `pending` state;
      - an outbox `CheckoutPlaced` event.
   3. `UNIQUE(customer_id, idempotency_key)` on `order_groups`. A duplicate submission returns the existing group, including its existing payment attempt.

3. **Step 2, attempt.** After commit, Payments creates a `PaymentAttempt(created)`, then calls the provider with these parameters:
   - amount equal to the group total;
   - currency MYR;
   - metadata `{order_group_id, attempt_id}`;
   - **provider idempotency key `attempt:{id}`**.

   It stores `provider_payment_id` (UNIQUE), sets `requires_payment_method`, and returns the client secret.

   Rules for attempts:
   - **At most one active attempt per group.** This is enforced with `order_groups.active_attempt_id` updated under a row lock. Creating a new attempt first cancels the previous provider intent.
   - **Provider unavailable** (timeout or 5xx): the attempt becomes `creation_failed`. The group stays `pending_payment` with its reservations, and the customer sees "Payment service unavailable, try again". Retry creates a new attempt. The provider idempotency key prevents duplicates where the first call actually succeeded.

4. **Step 3, confirm.** The browser confirms with Stripe.js (3-D Secure as needed). The UI shows "Confirming your payment…" and polls the group status. **The browser result is never trusted to mark anything paid.**

5. **Step 4, webhook ingestion.** `POST /webhooks/stripe`:
   1. Verify the signature. If invalid: **400**, nothing persisted, a security-log entry and a metric.
   2. `INSERT provider_events(provider, event_id UNIQUE, type, payload, received_at)`. On a duplicate key, return **200** and do nothing.
   3. Dispatch `ProcessProviderEvent` after commit. Return **200** quickly.

6. **Step 5, processing** (queued job, idempotent):
   1. Lock the attempt and the group (`FOR UPDATE`, always group first, then attempt).
   2. **Re-fetch the provider object** for authoritative current state. This handles out-of-order events.
   3. Check that amount and currency match the attempt. A mismatch becomes a `reconciliation_exception`, not paid.
   4. On `succeeded`:
      - attempt → `succeeded`;
      - group → `paid`;
      - VendorOrders → `awaiting_fulfilment`;
      - reservations consumed;
      - redemption and points confirmed;
      - ledger *capture* transaction posted (ADR-0016);
      - outbox `OrderGroupPaid` and per-vendor `VendorOrderPaid`;
      - all in **one transaction**.
   5. Already succeeded → no-op.
   6. On `payment_failed`: attempt → `failed` with the decline code. The group stays `pending_payment` until TTL so the customer can retry with another card.
   7. On `processing` or `requires_action`: record it. The sweeper honours it (ADR-0014).
   8. Mark the event `processed_at`. On exception, the job retries with backoff. A scheduled job re-dispatches events received but unprocessed for more than 5 minutes. This covers a crash mid-processing.

7. **Late success.** If a success arrives for an `expired` or `cancelled` group (the cancel lost a race):
   - Try to re-reserve every line.
   - On success, proceed as paid.
   - On partial failure, mark the group paid and immediately cancel the affected VendorOrders with reason `stock_unavailable_after_payment`. This triggers full refunds for those VendorOrders and notifies the customer.
   - Never silently keep money for goods that cannot ship.

8. **Expiry.** The sweeper (ADR-0014) cancels the provider intent before expiring a group. If the cancel fails because the intent already succeeded, the sweeper processes success instead.

9. **Refunds** (also ADR-0016):
   - `Refund` rows are per VendorOrder, with lines and amounts.
   - **Eligibility is owned by Ordering**, because Payments must not lock Ordering's tables. `vendor_orders` carries `refundable_cap_minor` (captured allocation, set at payment) and `refund_committed_minor`. A refund is committed by a guarded update:

     ```sql
     UPDATE vendor_orders SET refund_committed_minor = refund_committed_minor + :amt
      WHERE id = :id AND refund_committed_minor + :amt <= refundable_cap_minor
     ```

     Per-line counters (`vendor_order_lines.refund_committed_qty`) are guarded the same way, inside the same transaction.
   - In that transaction, Ordering calls `Payments\Contracts\Refunds::request(...)`, which creates the `Refund` row.
   - On a terminal `RefundFailed` event, Ordering decrements the committed amount, so the refund can be retried or re-requested.
   - The provider call happens after commit with idempotency key `refund:{id}`.
   - **Timeout leaves the refund `submitted`, never `failed`.** Resolution comes from `charge.refunded`/`refund.updated` webhooks or reconciliation. "Partially succeeds" becomes per-refund status, visible per VendorOrder. Failed refunds go to an operations queue.
   - Refunds are blocked while a dispute is open on the charge.

10. **Application idempotency store** `idempotency_keys(scope, key, request_hash, response, created_at)`, used for `POST /checkout`, refund requests and payout runs:
    - reusing a key with a **different** request hash returns **422** "idempotency key reused with different parameters";
    - the same request replays the stored response;
    - keys expire after 24 hours.

11. **Provider port.** `PaymentProvider` (in `Payments/Contracts`) has a Stripe adapter and a **deterministic fake** used in tests. The fake can be scripted to decline, time out, deliver duplicates, deliver out of order or deliver late.

## Positive consequences

- No locks across I/O.
- The provider's signed event drives money state.
- Every scenario in brief §11 has a defined outcome (`lifecycles/checkout-and-payment.md`).

## Negative consequences

- More moving parts: attempts, events, sweepers, idempotency store.
- The customer sees a short "confirming" state.

## Risks

- **Webhook endpoint misconfigured in the demo environment.** Mitigation: the reconciliation sweep re-fetches attempts stuck in `requires_payment_method` or `processing` past 30 minutes, and a health check shows the last webhook received.

## Testing implications

- With the fake provider, every §11 scenario is a named test.
- A Stripe test-mode smoke suite runs manually or nightly (not on every PR) using the Stripe CLI to forward webhooks.
- MySQL concurrency tests: duplicate checkout submit; duplicate webhook delivered concurrently; sweeper racing a webhook; two refunds on one VendorOrder.

## Migration implications

None (greenfield). The old code is not ported.

## Related

- ADR-0012, ADR-0014, ADR-0016, ADR-0017; `lifecycles/checkout-and-payment.md`; Phase 0 C1, C5, C6.
