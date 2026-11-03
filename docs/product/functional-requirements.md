# Functional Requirements Specification

- **Status:** Draft for owner review (Phase 1)
- **Date:** 2026-11-01
- **Source:** [product-requirements.md](product-requirements.md)

**Priority** uses MoSCoW: M = must, S = should, C = could. Every M requirement is in the v2 release gate.

**Acceptance criteria** are written so that each can become one or more Pest tests. **[INV-n]** refers to [`../architecture/INVARIANTS.md`](../architecture/INVARIANTS.md).

**Legacy IDs.** `REQ-VENDOR-*` and `REQ-VENDOR-MGMT-*` from `requirements/vendor/onboarding.md` and `requirements/platform/vendor-management.md` are carried into FR-VEN. Their mapping is listed in each row.

---

## FR-ID: Identity and accounts

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-ID-01 | M | Visitors can register with name, email and password, and must verify their email before checkout | Unverified users are redirected to verification when they reach checkout; the verification link is signed and expires in 60 minutes |
| FR-ID-02 | M | Login, logout, password reset, with rate limiting | Login is throttled to 5 attempts per minute per email+IP; reset tokens are single-use and expire |
| FR-ID-03 | M | Customers manage profile and address book (Malaysian address format, zone derived from state) | Address state determines the shipping zone; at most one default address |
| FR-ID-04 | M | Platform staff accounts are created by an Admin, require 2FA, and cannot register publicly | A staff login without 2FA confirmation cannot reach `/admin` |
| FR-ID-05 | M | Staff accounts cannot place orders or hold vendor membership [INV-2] | Attempting either fails with a domain error; covered by tests |
| FR-ID-06 | S | Customers can delete their account; personal data is anonymised and order records are retained | After deletion the name and email are anonymised; orders and ledger remain intact |
| FR-ID-07 | M | The UI language is selectable (en/ar/ms) and persisted per user; Arabic renders RTL | `<html lang dir>` reflects the choice; the preference survives logout and login |

## FR-VEN: Vendor onboarding and management

| ID | P | Requirement | Acceptance criteria | Legacy |
|---|---|---|---|---|
| FR-VEN-01 | M | A signed-in customer can submit a vendor application (store name, description, contact, category focus) | Submission does not create a Vendor; one open application per user | REQ-VENDOR-001 |
| FR-VEN-02 | M | Platform Operations approves or rejects applications with a reason | Approve creates a Vendor in `pending`, plus an Owner membership for the applicant, in one transaction. Reject creates no Vendor and notifies the applicant. | REQ-VENDOR-001, 001A |
| FR-VEN-03 | M | A Vendor has a stable id and a unique slug that never changes | The slug has a unique constraint; renaming the vendor keeps the slug | REQ-VENDOR-002 |
| FR-VEN-04 | M | Vendor lifecycle is `pending → approved ⇄ suspended`; other transitions are rejected and leave the status unchanged | Every invalid pair is tested; transitions are audit-logged | REQ-VENDOR-003…007, MGMT-002…005, 009 |
| FR-VEN-05 | M | Operating eligibility: only `approved` vendors' products are sellable. A `suspended` vendor can view history and fulfil existing orders, but cannot edit or create products or receive new orders. | Suspension hides products from the storefront (search documents updated); existing VendorOrders stay fulfillable | REQ-VENDOR-008, MGMT-010 |
| FR-VEN-06 | M | Vendor identity and history are preserved through suspension; vendors are never hard-deleted | No delete action exists; foreign keys use RESTRICT | REQ-VENDOR-009, MGMT-006 |
| FR-VEN-07 | M | The Vendor Owner invites team members by email with a role (manager, fulfilment, finance, support); invitations expire in 7 days | The invitee must not already belong to a vendor or be staff; exactly one owner per vendor | REQ-VENDOR-010 |
| FR-VEN-08 | M | The Owner changes roles and deactivates members; the Owner role is transferred explicitly | A deactivated member gets 403 on `/vendor` immediately (next request) | |
| FR-VEN-09 | M | Vendors manage their store profile: logo, banner, description, return-policy text | Shown on the public store page | |
| FR-VEN-10 | M | Vendor data is isolated: no vendor context can read or modify another vendor's records [INV-1] | Isolation test suite (merge gate) | MGMT-007 |

## FR-CAT: Catalogue

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-CAT-01 | M | Platform Operations manages a category tree (max depth 3) with names in en/ar/ms and an optional commission override | A category with products cannot be deleted, only deactivated |
| FR-CAT-02 | M | Platform defines option attributes (for example Colour, Size) with values, usable for variants | Variant option combinations are unique per product |
| FR-CAT-03 | M | Vendors create products: title, description, brand, category (leaf), images (≤ 8, ordered), status `draft/active/inactive` | Only an `approved` vendor can set `active`; image MIME type and size are validated; images are stored privately until processed |
| FR-CAT-04 | M | Products have ≥ 1 variant; each variant has a SKU (unique per vendor), price, optional compare-at price (> price), weight and option values | Price > 0; compare-at > price or null |
| FR-CAT-05 | M | A product is **sellable** iff: product `active` ∧ not blocked ∧ vendor `approved` ∧ ≥ 1 variant active | The storefront and checkout use the same sellability rule (single implementation) |
| FR-CAT-06 | M | Platform Operations can block or unblock a product with a reason (reactive moderation, P-2) | A blocked product disappears from the storefront; the vendor sees the reason |
| FR-CAT-07 | M | Price changes never affect existing orders [INV-6] | Snapshot test |
| FR-CAT-08 | S | Vendors import products by CSV with a validation report (dry-run first) | Invalid rows are reported with line numbers; no partial import unless confirmed |

## FR-INV: Inventory

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-INV-01 | M | Each variant has on-hand and reserved quantities; available = on-hand − reserved, never negative [INV-7] | DB CHECK constraint present; tests attempt violations |
| FR-INV-02 | M | Checkout reserves stock atomically; insufficient stock fails the whole checkout and names the short lines | Parallel test: N buyers for the last unit, exactly one succeeds |
| FR-INV-03 | M | Reservations expire after 15 minutes (P-4), with one extension while payment is processing | Sweeper test with a frozen clock |
| FR-INV-04 | M | Payment success consumes reservations; failure, expiry or cancellation releases them; each happens at most once | Duplicate events give a single movement |
| FR-INV-05 | M | Vendors adjust stock by delta (receive, damage, correction with reason) or by absolute count with conflict detection | A stale count returns 409 with the current value; on-hand below reserved is rejected |
| FR-INV-06 | M | Every change records a stock movement with type, deltas, reference and actor; the vendor can view the history per variant | Movement sums equal on-hand (reconciliation test) |
| FR-INV-07 | S | Low-stock threshold per variant, shown in the vendor dashboard | |
| FR-INV-08 | M | A returned item is restocked only when the vendor marks it received and resellable | |

## FR-SHP: Shipping

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-SHP-01 | M | Each vendor sets a flat rate per zone (Peninsular, East Malaysia) and an optional free-shipping threshold | Checkout quotes per VendorOrder, using the vendor's goods subtotal before discounts |
| FR-SHP-02 | M | A vendor that has no rate for the customer's zone cannot be checked out to that address | Checkout shows which vendor cannot ship there |
| FR-SHP-03 | M | Vendors enter carrier and tracking number when marking shipped | Required fields; the customer sees them on the order timeline |

## FR-CART: Cart

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-CART-01 | M | Visitors and customers add variants from any number of vendors to one cart, grouped by vendor in the UI | |
| FR-CART-02 | M | A guest cart merges into the customer cart at login (quantities summed, capped at available) | Merge is idempotent |
| FR-CART-03 | M | The cart revalidates on view: price changes, unavailable items and quantity above available are flagged, and checkout is blocked until acknowledged | |
| FR-CART-04 | M | Quantity per line is limited to 1–20 (configurable) and to available stock | |
| FR-CART-05 | M | A cart is owned by exactly one session or customer; no endpoint accepts a cart id from the client | Test: another user's cart cannot be read or checked out |

## FR-CHK: Checkout

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-CHK-01 | M | Checkout requires a verified customer account (P-1) | |
| FR-CHK-02 | M | The customer selects a shipping address; the system quotes shipping per vendor and shows a breakdown per vendor and in total | |
| FR-CHK-03 | M | The customer may apply one coupon and redeem points; the system shows the allocation as a total discount | Invalid coupons give a reason code |
| FR-CHK-04 | M | Placing the order re-prices server-side; if the total differs from the confirmed total, nothing is charged and the customer reviews again | 409 test |
| FR-CHK-05 | M | Placing is idempotent per checkout key; double-submit creates one OrderGroup [INV-9] | Parallel test |
| FR-CHK-06 | M | One payment for the group total by card (Stripe test mode, 3-D Secure supported) | |
| FR-CHK-07 | M | The payment outcome is determined only by verified provider events; the customer sees "confirming" until then | Closing the browser does not change the outcome (test) |
| FR-CHK-08 | M | After a declined payment the customer can retry with another card until the reservation expires | One active attempt at a time |
| FR-CHK-09 | M | If the provider is unavailable the customer sees a retryable error; no order is paid and stock stays reserved until TTL | Fake-provider outage test |
| FR-CHK-10 | M | Successful payment creates one VendorOrder per vendor in `awaiting_fulfilment`, notifies each vendor and emails the customer one confirmation | |

## FR-ORD: Orders and fulfilment

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-ORD-01 | M | Customers see their orders as groups expanding into vendor shipments, each with its own status timeline | |
| FR-ORD-02 | M | Vendors see only their VendorOrders, filterable by status and date, with the shipping address and items | No sibling or group totals are exposed [INV-1] |
| FR-ORD-03 | M | Vendor transitions follow the state machine in ADR-0012; invalid transitions are rejected | Every pair is tested; concurrent transition test |
| FR-ORD-04 | M | A vendor cancels with a reason before shipping, which triggers a full refund of that VendorOrder and releases stock | Siblings unaffected [INV-4] |
| FR-ORD-05 | M | A customer cancels a VendorOrder while `awaiting_fulfilment` (P-8) | |
| FR-ORD-06 | M | Delivered is confirmed by the vendor or the customer, or automatically after 10 days shipped (P-3) | |
| FR-ORD-07 | M | Completed is set automatically when the return window has ended with no open return or dispute; this triggers payable maturity and points award | |
| FR-ORD-08 | M | Every transition is recorded in the status history with actor and reason | |
| FR-ORD-09 | S | Vendors export orders as CSV for a date range | Only their own orders |

## FR-RET: Returns

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-RET-01 | M | A customer requests a return for delivered lines within the return window (quantity per line, reason, optional photos) | No more than the purchased minus already-returned quantity |
| FR-RET-02 | M | The vendor approves or rejects (with reason) within 3 days; after 3 days with no response the request escalates to Platform Operations | |
| FR-RET-03 | M | On approval and receipt, the vendor marks items received (resellable or not), which triggers the refund of those lines | |
| FR-RET-04 | M | Platform Operations can override a vendor rejection | Audit-logged |
| FR-RET-05 | S | "Not received" claim on a shipped order past its expected window, handled as a return without item receipt | |

## FR-REF: Refunds

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-REF-01 | M | Refunds are per VendorOrder and per line (goods + proportional shipping when all lines are refunded); the amount never exceeds the refundable remainder [INV-5] | Concurrent refund test |
| FR-REF-02 | M | Initiators: system (cancellation, return receipt, stock unavailable after payment), Vendor Owner/Finance (goodwill, capped), Platform Finance (override) | |
| FR-REF-03 | M | Refund lifecycle `requested → approved → submitted → succeeded / failed`; a provider timeout leaves it `submitted` until confirmed | |
| FR-REF-04 | M | Refunds reverse the refunded lines' commission, discount allocation and points exactly | Ledger tests |
| FR-REF-05 | M | No refund while a dispute is open on the payment | |
| FR-REF-06 | M | Customer, vendor and finance see refund status per VendorOrder | |

## FR-PAY / FR-FIN: Payments and finance

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-PAY-01 | M | Webhooks are signature-verified; invalid signatures get 400 and nothing is stored | |
| FR-PAY-02 | M | Each provider event is processed at most once in effect [INV-9] | Concurrent duplicate-delivery test |
| FR-PAY-03 | M | An amount or currency mismatch between provider and attempt never marks paid; it raises a reconciliation exception | |
| FR-FIN-01 | M | Every money movement posts a balanced ledger transaction exactly once [INV-10] | |
| FR-FIN-02 | M | The commission rate (vendor → category → default) is frozen per VendorOrder [INV-6] | Changing rates affects new orders only |
| FR-FIN-03 | M | Vendors see balances (pending, available, held, receivable, paid out) and a statement of ledger lines, exportable as CSV | Balance = Σ statement |
| FR-FIN-04 | M | A weekly simulated payout run pays each vendor's available balance ≥ the minimum, net of receivables; it is idempotent | Running it twice concurrently produces one payout per vendor |
| FR-FIN-05 | M | Disputes hold the allocated vendor funds and resolve per ADR-0016 | |
| FR-FIN-06 | M | Reconciliation: nightly invariant checks and provider matching; exceptions are listed for Finance to resolve | Injected mismatch is detected |
| FR-FIN-07 | M | Platform Finance edits the default and override commission rates; changes are audit-logged | |
| FR-FIN-08 | S | Platform finance dashboard: GMV, commission, refunds, payables, payouts by period | |

## FR-PRO: Promotions

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-PRO-01 | M | Platform Operations creates promotions: coupon code or automatic; percentage or fixed; minimum spend; target all, categories or vendors; total and per-customer usage limits; date window | |
| FR-PRO-02 | M | One coupon per checkout; the best automatic promotion is applied when no coupon is used (no stacking in v2) | |
| FR-PRO-03 | M | Discounts are allocated to eligible lines by largest remainder and stored per line [INV-8] | Property test: sum exact |
| FR-PRO-04 | M | Usage limits hold under concurrency (redemption reserved at checkout, released on expiry) | Parallel test on the last allowed use |
| FR-PRO-05 | M | Promotions are platform-funded; vendor payable is unaffected | Ledger test |

## FR-LOY: Loyalty and referrals

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-LOY-01 | M | Points are earned on completed VendorOrders at the configured rate (P-5), recorded in a points ledger | Award is idempotent per VendorOrder |
| FR-LOY-02 | M | Points are redeemed at checkout up to the cap; held at placement, confirmed on payment, released on expiry | |
| FR-LOY-03 | M | A refund of redeemed-point lines returns the points; a refund after award claws back the earned points (balance may not go below zero; the shortfall is recorded) | |
| FR-LOY-04 | M | Each customer has a referral code; the referee benefit and referrer award follow P-6; self-referral and repeat referral are blocked | |
| FR-LOY-05 | S | Points balance and history page | |

## FR-REV: Reviews

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-REV-01 | M | Customers review a purchased product after delivery: one review per purchased VendorOrder line, rating 1–5, text, optional photos | Unique (line) |
| FR-REV-02 | M | Vendors reply once per review (editable) | |
| FR-REV-03 | M | Platform Operations hides or unhides reviews with a reason; product rating aggregates only visible reviews | |
| FR-REV-04 | S | Customers report reviews | |

## FR-ENG: Wishlist and follows

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-ENG-01 | M | Customers add and remove products to a wishlist; unavailable items are shown as such | |
| FR-ENG-02 | M | Customers follow or unfollow vendor stores; the account page shows new products from followed stores | |

## FR-SRCH: Search and discovery

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-SRCH-01 | M | Keyword search across sellable products with facets: category, price range, vendor, rating ≥ n, in stock; sorting by relevance, price, newest, rating | Hidden products never returned |
| FR-SRCH-02 | M | Category pages list with the same facets | |
| FR-SRCH-03 | M | Search documents update within about 1 minute of product, stock, vendor or review changes | Outbox-driven |
| FR-SRCH-04 | S | Arabic queries normalise letter forms and diacritics | |

## FR-NOT: Notifications

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-NOT-01 | M | In-app notifications and email for: order placed or paid, vendor order received, shipped, delivered, cancelled, return updates, refund succeeded, payout made, application decision, team invite | Sent once per event (inbox de-duplication) |
| FR-NOT-02 | M | Emails are localised in the recipient's language | |
| FR-NOT-03 | S | Users manage email preferences for non-transactional notifications | |

## FR-ADM: Platform administration

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-ADM-01 | M | Staff roles and permissions per `security-model.md`; every action is policy-checked | Denied-access tests per resource |
| FR-ADM-02 | M | Audit log for: vendor lifecycle, moderation, refunds, commission changes, payouts, reconciliation resolutions, impersonation, staff changes | Each action writes an audit row (actor, before/after, IP) |
| FR-ADM-03 | M | Admin impersonates vendor members or customers for support; impersonation is banner-marked and audited, and money-moving actions are blocked | |
| FR-ADM-04 | M | Operational health: outbox backlog and dead letters, failed jobs, last webhook received, reconciliation exceptions, sweeper last run | |
| FR-ADM-05 | M | Order lookup across vendors by reference, customer email or provider payment id | |

## FR-DEMO: Demo environment

| ID | P | Requirement | Acceptance criteria |
|---|---|---|---|
| FR-DEMO-01 | M | One command builds the demo from an empty database (ADR-0021), deterministically | CI runs it; invariants pass |
| FR-DEMO-02 | M | Documented demo logins per role; demo-only banner; Stripe test cards documented | |
