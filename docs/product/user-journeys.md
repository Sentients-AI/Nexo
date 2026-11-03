# User Journeys and Workflows

- **Status:** Draft for owner review (Phase 1)
- **Date:** 2026-11-01

Each journey lists its steps, the requirements it exercises, and the failure branches the design must handle. The journeys drive the Phase 2 screen inventory and the Phase 9 end-to-end tests.

---

## Customer journeys

### CJ-1 Discover and buy from several vendors (happy path)

1. Visitor lands on home (featured categories, curated collections, stores to follow) → category page → applies facets.
2. Opens a product page and selects variant options:
   - price and stock update per variant;
   - the vendor card links to the store page.
3. Adds three items from three vendors. The cart groups lines by vendor and shows per-vendor subtotals and shipping estimates.
4. Proceeds to checkout and is asked to sign in or create an account. The guest cart is merged (FR-CART-02).
5. Selects an address. Shipping is quoted per vendor, then the customer applies a coupon and redeems points. The summary shows goods, shipping per vendor, discount, points and total.
6. Places the order:
   - the stock is reserved;
   - the payment form shows a timer for the reservation;
   - the customer pays with a test card and completes 3-D Secure.
7. Sees "Confirming your payment…", then the confirmation page: group reference and three vendor shipments.
8. Receives one confirmation email. Each vendor gets a new-order notification.

**Requirements:** FR-SRCH-01, FR-CAT-05, FR-CART-01…04, FR-CHK-01…10.

**Failure branches:**

| Branch | Expected behaviour |
|---|---|
| Price changed between cart and placement | 409; review the new total |
| Line out of stock at placement | Order not placed; short lines named |
| Card declined | Retry with another card within TTL |
| Provider unavailable | Retryable error; nothing charged |
| Browser closed after paying | Order still marked paid by webhook; email sent |
| Reservation expired before paying | Group expired; the customer can return to the cart, which is kept |
| Double-click on "Place order" | One order |

### CJ-2 Track and receive

1. Account → Orders. Each group expands into vendor shipments, each with a timeline: paid → processing → shipped (carrier + tracking) → delivered.
2. The customer confirms delivery, or it auto-confirms after 10 days.

### CJ-3 Cancel before shipping

1. On a vendor shipment in `awaiting_fulfilment`, the customer chooses Cancel and gives a reason.
2. That VendorOrder is cancelled:
   - a full refund for that vendor's portion (goods + shipping, minus nothing);
   - points and discount allocation for those lines are returned.
3. The other shipments are unchanged.

**Failure branch:** the vendor moved it to `processing` at the same moment. The customer sees "This shipment is already being prepared; request a return after delivery." (409).

### CJ-4 Return and partial refund

1. Within 7 days of delivery, the customer requests a return of 1 of 2 units on one line, with a reason and photos.
2. The vendor approves and the customer ships the item back (out of band).
3. The vendor marks it received and resellable. The refund is created for exactly that unit's goods value, net of its allocated discount.
4. The customer sees the refund go `submitted` → `succeeded`. Points for that unit are adjusted.

**Branches:**

| Branch | Expected behaviour |
|---|---|
| Vendor rejects | Customer can escalate; Platform Operations arbitrates |
| Vendor silent for 3 days | Auto-escalation |
| Provider refund timeout | Status stays "Processing refund", resolved by webhook or reconciliation |

### CJ-5 Review

After delivery, the customer reviews a purchased line (rating, text, photos). The vendor replies. Platform Operations may hide the review.

### CJ-6 Engage

- Wishlist add and remove.
- Follow a store; see new products from followed stores.
- Share a referral code; the friend gets a discount; the customer earns points when the friend's order completes.

---

## Vendor journeys

### VJ-1 Apply and get approved

1. A signed-in user selects "Sell on Nexo" and submits an application.
2. Platform Operations reviews it and approves:
   - a Vendor is created as `pending` with the applicant as Owner;
   - an operator then approves the vendor (`approved`).
3. The Owner is notified, opens `/vendor` and completes the store profile and shipping rates. A checklist shows readiness: profile, shipping rates for at least one zone, at least one active product.

**Branch:** rejected application, with reason; the applicant may apply again after 30 days.

### VJ-2 Build the catalogue

1. Create a product: category, details and images.
2. Define options (Colour × Size), which generates the variant matrix. Set SKU, price and stock per variant.
3. Activate the product. It appears in search within about 1 minute.
4. Optionally run a CSV import: dry-run report, then confirm.

### VJ-3 Fulfil an order

1. The vendor receives a notification. The orders list shows filters by status.
2. Opens the VendorOrder: items, shipping address, customer name and phone.
3. Moves it to `processing` (packing), then `shipped` with carrier and tracking.
4. Later the order becomes `delivered`, then `completed`. The earnings change from pending to available.

**Branches:**

| Branch | Expected behaviour |
|---|---|
| Item cannot be supplied | Vendor cancels with reason; automatic full refund for this VendorOrder; stock returned |
| Concurrent edit by two team members | One transition wins; the other sees "Status changed by <name>" (409) |

### VJ-4 Manage stock

- Receive stock (delta) or do a stock count (absolute).
- A conflicting count gets 409 with the current figure.
- Reserved quantity is visible, with the explanation "held in unpaid checkouts".

### VJ-5 Handle a return

Approve or reject a return, then mark the items received (resellable or damaged). The refund is triggered automatically. The vendor statement shows the reversal line, with commission reversed.

### VJ-6 Earnings and payouts

1. The Finance role opens Earnings and sees balances: pending, available, held, receivable, paid out.
2. The statement lists every ledger line, linked to orders, refunds and disputes. It can be exported as CSV.
3. The Monday payout run creates a simulated payout, labelled "Simulated (demo)".

### VJ-7 Manage the team

The Owner invites a fulfilment member. The member accepts and sees only the permitted menus. The Owner deactivates the member, whose access ends on their next request.

---

## Platform staff journeys (Filament)

| ID | Journey | Steps |
|---|---|---|
| PJ-1 | Vendor applications | Queue → review → approve or reject → approve vendor → (later) suspend with reason → reactivate |
| PJ-2 | Catalogue moderation | Report or queue → block product with reason → vendor notified → unblock |
| PJ-3 | Return arbitration | Escalated returns queue → view evidence → uphold or override → refund triggered |
| PJ-4 | Dispute | Dispute appears (provider event) → funds held for affected vendors → upload evidence (simulated) → record outcome (won or lost) → ledger postings |
| PJ-5 | Refund failure | Failed refund queue → inspect provider error → retry (same idempotency key) or mark resolved with note |
| PJ-6 | Reconciliation | Nightly report → exceptions list → investigate (links to attempt, event, ledger) → resolve with note (audit) |
| PJ-7 | Payout run | View the upcoming run → trigger manually (idempotency key) or wait for the schedule → payouts list |
| PJ-8 | Commission | Change default, category or vendor rate → effective for new orders only → audit entry |
| PJ-9 | Promotions | Create coupon → limits and targeting → monitor redemptions |
| PJ-10 | Support | Look up an order by reference, email or payment id → impersonate the customer or vendor member (audited, no money actions) |
| PJ-11 | Health | Outbox backlog, dead letters (retry), failed jobs, last webhook, sweeper heartbeat |
