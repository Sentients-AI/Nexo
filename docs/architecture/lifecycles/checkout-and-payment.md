# Checkout and Payment Lifecycle

- **Status:** Draft for owner review (Phase 1). It implements ADR-0015, ADR-0012 and ADR-0014.
- **Date:** 2026-11-01

## 1. State machines

### OrderGroup

```
             place (TX1)
   (cart) ───────────────► pending_payment ──── payment captured ────► paid
                               │   │
          customer abandons ◄──┘   └── TTL elapsed, no live payment ──► expired
          (explicit cancel)                     (sweeper)
                 ▼
             cancelled
```

### PaymentAttempt

```
 created ──► requires_payment_method ──► requires_action (3DS) ──► processing ──► succeeded
    │                 │   │                      │                       │
    ▼                 │   └──────────────────────┴──────► failed ◄───────┘
 creation_failed      └──────────────────────────────────► cancelled (superseded or group expired)
```

- At most one attempt per group is in a non-terminal state.
- Terminal states are `succeeded`, `failed`, `cancelled` and `creation_failed`.

## 2. Sequence: happy path

```
Browser            Ordering                  Inventory/Promo/Loyalty   Payments                 Stripe
  │ POST /checkout   │                              │                     │                        │
  │ (checkoutKey,    │ re-price; compare hash        │                     │                        │
  │  summaryHash) ──►│ BEGIN                         │                     │                        │
  │                  │  insert group+vendor orders   │                     │                        │
  │                  │  reserve (sorted) ───────────►│ conditional UPDATEs │                        │
  │                  │  hold promo / points ────────►│                     │                        │
  │                  │  outbox CheckoutPlaced        │                     │                        │
  │                  │ COMMIT                        │                     │                        │
  │                  │ create attempt ──────────────────────────────────►│ insert attempt         │
  │                  │                               │                     │ create PI (idem key) ─►│
  │◄─ redirect /pay (clientSecret, expiresAt) ───────────────────────────────────────────────────┤
  │ confirmPayment (Stripe.js, 3DS) ─────────────────────────────────────────────────────────────►│
  │ poll /status every 2 s                                                                        │
  │                                                                     │◄── webhook succeeded ──┤
  │                                                                     │ verify sig; insert event (unique); 200
  │                                                                     │ job: lock group→attempt; retrieve PI
  │                  │◄─ OrderPayments::markPaid (same TX) ─────────────│ BEGIN … COMMIT         │
  │                  │  group paid; VOs awaiting_fulfilment; consume; confirm promo/points; outbox
  │◄─ status: paid ──┤                                                                            │
```

## 3. Scenario matrix (brief §10 and §11)

Each row becomes a named Pest test in Phase 5. **F** means it runs with the fake provider in CI. **S** means it is a Stripe test-mode smoke test. **C** means a MySQL parallel concurrency test.

| # | Scenario | Expected outcome | Recovery mechanism | Test |
|---|---|---|---|---|
| P1 | Payment succeeds | Group `paid`; one VendorOrder per vendor `awaiting_fulfilment`; stock consumed; promo and points confirmed; capture posted; one email to the customer, one notification per vendor | — | F, S |
| P2 | Payment declined | Attempt `failed` with decline code; group stays `pending_payment`; reservation kept; the customer may retry until `expires_at` | Retry creates a new attempt (the previous one is already terminal) | F, S |
| P3 | Payment remains pending (`processing`/`requires_action`) | Group `pending_payment`; the sweeper extends the reservation once (≤ 15 min); then the attempt is cancelled at the provider and the group expires | Stuck-attempt sweep re-fetches after 30 min | F |
| P4 | Webhook arrives late (after `expires_at`) | Late-success path: re-reserve; if all lines are available → paid; otherwise paid + affected VendorOrders cancelled with `stock_unavailable_after_payment` and refunded | Reconciliation catches a success with no webhook at all | F |
| P5 | Webhook delivered multiple times (sequentially or concurrently) | Exactly one effect: second insert hits UNIQUE (provider, event_id) → 200 no-op; a different event id for the same success finds the attempt already `succeeded` → no-op | Unique keys | F, C |
| P6 | Webhook signature invalid | 400; nothing stored; security log + metric | — | F |
| P7 | Customer closes the browser during payment | No effect on outcome; the webhook completes the order; the confirmation email is sent | — | F |
| P8 | Checkout retried (double click, refresh, back button) | Same `checkoutKey` → the same OrderGroup and active attempt are returned (idempotency store replay) | UNIQUE (customer_id, idempotency_key) | F, C |
| P9 | Idempotency key reused with different parameters | 422 `idempotency.key_reused`; nothing created | Request-hash comparison | F |
| P10 | Price changed between review and placement | 409 `checkout.price_changed` with the fresh summary; nothing reserved or charged | — | F |
| P11 | Insufficient stock at placement | TX1 rolls back entirely; short lines are reported; nothing charged | — | F, C |
| P12 | Payment provider unavailable at attempt creation | Attempt `creation_failed`; the group remains `pending_payment` with reservations; the customer sees a retryable error | Retry; the provider idempotency key guards against a hidden success | F |
| P13 | Provider unavailable during webhook processing (re-fetch fails) | Job fails and retries with backoff; the event stays unprocessed | Unprocessed-event sweep after 5 min | F |
| P14 | Application crashes after TX1 commit, before attempt creation | Group `pending_payment` without an attempt; the pay page creates the attempt on load (idempotent); if the customer never returns, the group expires and stock is released | Sweeper | F |
| P15 | Application crashes mid-success-processing | The transaction rolls back entirely; the event is still unprocessed; the retry succeeds once | Queue retry and the unprocessed-event sweep | F |
| P16 | Amount or currency mismatch between provider and attempt | Not marked paid; `reconciliation_exception` (amount_mismatch); Finance alerted | Manual resolution | F |
| P17 | Customer starts a new attempt while one is `requires_action` | The previous attempt is cancelled at the provider first; if the cancel fails because it already succeeded → process success and return "already paid" | Group row lock serialises attempt changes | F, C |
| P18 | Success webhook races the expiry sweeper | Both lock the group row; whichever is first: if the sweeper wins it cancels the PI (the cancel fails if it already succeeded → it switches to the success path); if the webhook wins the group is `paid` and the sweeper skips it | Row locks and status guards | C |
| P19 | Refund partially succeeds (a cancellation affecting several VendorOrders, one refund fails) | Each refund is independent: succeeded ones are posted; the failed one is `failed` with code, `refund_committed` is decremented, and it appears in the Finance queue | Retry with the same refund idempotency key | F |
| P20 | Repeated refund requests (double click, two staff) | One refund committed; the second fails the guarded counter with 422 `refund.exceeds_refundable`, or replays the idempotent response | Guarded counters | F, C |
| P21 | Refund call times out | Refund stays `submitted`; resolved by `refund.updated` or the refund sweep; never re-sent with a new key | Provider idempotency key | F |

## 4. Customer-visible states

| Internal state | Customer sees |
|---|---|
| group `pending_payment`, attempt `requires_payment_method` | "Complete your payment" + reservation countdown |
| attempt `requires_action`/`processing` | "Confirming your payment…" |
| attempt `failed` | "Your card was declined (reason). Try another card." + countdown |
| attempt `creation_failed` | "Payment service is temporarily unavailable. Try again." |
| group `paid` | Confirmation page |
| group `expired` | "Your reservation expired and you were not charged. Your cart is still saved." |
| paid + VendorOrder cancelled `stock_unavailable_after_payment` | Confirmation + notice "One item couldn't be fulfilled; you've been refunded RM x" |

"Pending" never looks like "failed" (design principle carried over from the 2026-08-30 vision).
