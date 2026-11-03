# Integration Contracts and the API / Frontend Contract Strategy

- **Status:** Draft for owner review (Phase 1). It implements ADR-0018, ADR-0015 and ADR-0010.
- **Date:** 2026-11-01

Three kinds of contract exist:

1. **Browser ↔ Laravel**: Inertia pages, plus the few JSON endpoints.
2. **Module ↔ module**: `Contracts` interfaces and integration events.
3. **Nexo ↔ Stripe**: the payment provider port.

---

## 1. Browser ↔ Laravel

### 1.1 Transport

| Kind | Used for | Format |
|---|---|---|
| Inertia page visit | Every page in storefront, account and vendor | Props from `*PageData` objects (ADR-0018) |
| Inertia form submit | Every mutation from Vue | Form request validation → redirect with flash, or 422 errors bag |
| Inertia partial reload | Facet changes, cart drawer, dashboard widgets | `only: [...]` prop keys |
| JSON | `GET /checkout/{reference}/status` (payment polling), `POST /webhooks/stripe` | `application/json`; errors as `application/problem+json` |

**Not provided:** a public REST API, versioned `/api/v1` and a mobile API. If one is introduced, a new ADR defines OpenAPI-first contracts.

### 1.2 Shape rules (normative)

| Data | Wire format | Example |
|---|---|---|
| Money | `{ amount: int, currency: "MYR" }` (minor units) | `{ "amount": 12990, "currency": "MYR" }` = RM 129.90 |
| Rate | `{ bps: int }` | `{ "bps": 1000 }` |
| Date-time | ISO 8601 UTC with `Z` | `"2026-11-01T03:15:00Z"` |
| Date | `YYYY-MM-DD` | — |
| Enum | String literal; label via i18n key `enums.<Enum>.<value>` | `"awaiting_fulfilment"` |
| Identifier | `publicId` (ULID) or `reference`; never `id` | `"01JC…"` |
| Optional values | Key always present; `null` when absent | — |
| Collections | `Paginated<T>` or `CursorPaginated<T>` | ADR-0018 §4 |
| Permissions | `can: { cancel: boolean, refund: boolean, … }` computed server-side | — |
| Allowed actions | `allowedTransitions: VendorOrderStatus[]` | — |

### 1.3 Error contract

| Situation | HTTP | Inertia behaviour | Code examples |
|---|---|---|---|
| Validation | 422 | `errors` bag per field | — |
| Domain rule violation | 409 or 422 | Redirect back with flash `{ type: "error", code, message }` | `stock.insufficient`, `checkout.price_changed`, `order.transition_conflict`, `refund.exceeds_refundable`, `promotion.limit_reached`, `idempotency.key_reused` |
| Not found or not owned | 404 | Error page | — |
| Forbidden | 403 | Error page (no detail) | — |
| Rate limited | 429 | Flash with retry hint | — |
| Server error | 500 | Generic error page, correlation id shown | — |

Domain error codes live in a PHP enum `ErrorCode`. They are exported to TypeScript and have i18n messages. The message text is never built from exception messages.

### 1.4 Checkout-specific contract

1. The checkout page props include `checkoutKey` (UUID), `summary` (per-vendor groups, totals) and `summaryHash`. The hash is a server HMAC of the priced summary.
2. `POST /checkout` sends `{ checkoutKey, addressId, shippingSelections, promotionCode?, pointsToRedeem, summaryHash }`. It receives either:
   - a redirect to `/checkout/{reference}/pay`, with props `{ clientSecret, publishableKey, expiresAt, total }`; or
   - a 409 `checkout.price_changed`, with a fresh summary.
3. `GET /checkout/{reference}/status` returns `{ status: "pending_payment" | "paid" | "expired" | "cancelled", attempt: { status, failureCode } }`. The client polls every 2 seconds for up to 60 seconds, then shows "We're still confirming; you'll get an email".

### 1.5 Contract verification

- Generated `types/generated.d.ts` with a CI diff check.
- `vue-tsc --noEmit`.
- `assertInertia` tests for component name and prop structure on every page, using one dataset per page.
- Error codes: an enum ↔ i18n key coverage test.

---

## 2. Module ↔ module

### 2.1 Synchronous contracts (initial list; signatures are indicative)

| Interface (owner) | Methods | Used by |
|---|---|---|
| `Identity\Contracts\Customers` | `get(customerId): CustomerSummary`, `addressSnapshot(addressId, customerId): AddressSnapshot` | Ordering, Notifications, Loyalty |
| `Vendors\Contracts\VendorDirectory` | `get(vendorId): VendorSummary`, `canSell(vendorId): bool`, `memberContext(userId): ?VendorContext` | Catalogue, Ordering, Search, Inventory, Shipping |
| `Catalogue\Contracts\Sellables` | `snapshot(variantIds): VariantSnapshot[]` (price, title, options, vendorId, categoryId, sellable) | Ordering, Promotions, Inventory |
| `Inventory\Contracts\StockReservations` | `reserve(groupId, lines[], ttl)`, `consume(groupId)`, `release(groupId, reason)`, `restockCancelled(lineId, qty)`, `available(variantIds)` | Ordering |
| `Shipping\Contracts\ShippingQuotes` | `quote(vendorId, zoneCode, goods): Money\|Unavailable` | Ordering |
| `Promotions\Contracts\PromotionEngine` | `evaluate(cartSnapshot, code?): Allocation`, `hold(groupId, …)`, `confirm(groupId)`, `release(groupId)` | Ordering |
| `Loyalty\Contracts\Points` | `balance(customerId)`, `hold(groupId, points)`, `confirm(groupId)`, `release(groupId)` | Ordering |
| `Finance\Contracts\CommissionRates` | `resolve(vendorId, categoryId, at): BasisPoints` | Ordering |
| `Payments\Contracts\PaymentAttempts` | `create(groupRef, amount, idempotencyKey): AttemptResult`, `status(groupId): AttemptStatus`, `cancelActive(groupId)` | Ordering |
| `Payments\Contracts\Refunds` | `request(vendorOrderRef, lines[], amount, initiator, idempotencyKey): RefundRef` | Ordering |
| `Payments\Contracts\OrderPayments` *(port owned by Payments, implemented by Ordering)* | `markPaid(groupId, attemptRef): void` | Payments |
| `Ordering\Contracts\PurchaseVerification` | `receivedLine(customerId, lineRef): ?PurchasedLine` | Engagement |

Each interface has a contract test suite in `tests/Contracts/` that runs against the real implementation, and against the in-memory fake where one exists.

### 2.2 Integration events

- The event list and subscribers are in `dependency-rules.md` §3.
- Every event payload is a `final readonly` DTO with `eventId` (ULID), `occurredAt` and `version`.
- A breaking payload change creates a new event version. Consumers handle both versions until all outbox rows of the old version are drained.

---

## 3. Nexo ↔ Stripe (payment provider port)

### 3.1 Port (`Payments\Contracts\PaymentProvider`, implemented in `Payments\Infrastructure`)

| Operation | Stripe call | Idempotency key |
|---|---|---|
| `createPayment(amount, currency, metadata)` | `paymentIntents.create` (automatic payment methods restricted to `card`) | `attempt:{attempt_id}` |
| `retrievePayment(providerId)` | `paymentIntents.retrieve` | — |
| `cancelPayment(providerId)` | `paymentIntents.cancel` | `attempt-cancel:{attempt_id}` |
| `refund(providerPaymentId, amount, metadata)` | `refunds.create` | `refund:{refund_id}` |
| `retrieveRefund(providerRefundId)` | `refunds.retrieve` | — |
| `balanceTransactions(window)` | `balanceTransactions.list` | — |
| `verifyWebhook(payload, signature)` | `Webhook::constructEvent` (tolerance 300 s) | — |

### 3.2 Webhook events consumed

| Event | Effect |
|---|---|
| `payment_intent.succeeded` | Payment success path (ADR-0015 §6) |
| `payment_intent.payment_failed` | Attempt failed (retry allowed) |
| `payment_intent.processing`, `payment_intent.requires_action` | Record the state (affects the sweeper) |
| `payment_intent.canceled` | Attempt cancelled |
| `charge.refunded`, `refund.updated`, `refund.failed` | Refund resolution |
| `charge.dispute.created`, `charge.dispute.closed` | Dispute lifecycle |
| `balance.available` *(informational)* | Ignored in v2 |

Unknown event types are stored and marked processed with no effect. They are counted in health metrics.

### 3.3 Fake provider (tests and CI)

`FakePaymentProvider` is deterministic and scriptable:

- `willDecline(code)`;
- `willTimeoutOnce()`;
- `willSucceedAfter(ms)`;
- `deliverEventTwice()`;
- `deliverOutOfOrder()`;
- `failRefund(code)`;
- `openDispute(amount)`.

It emits signed events through the same webhook controller, so the ingestion path is tested end to end.

### 3.4 Verification against real Stripe (D-14)

A manual or nightly suite runs against Stripe test mode, using the Stripe CLI to forward webhooks. It covers:

- card success;
- decline;
- 3-D Secure;
- refund;
- a dispute test card.

It is not a PR gate, because it needs network access and secrets.
