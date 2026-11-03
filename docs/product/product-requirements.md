# Nexo — Product Requirements Document

- **Status:** Draft for owner review (Phase 1)
- **Date:** 2026-11-01
- **Supersedes:** `vision.md`, `scope.md`, `goals.md`, `personas.md` (aljebal-albeedos framing), per ADR-0007
- **Related:** [functional-requirements.md](functional-requirements.md) · [non-functional-requirements.md](non-functional-requirements.md) · [user-journeys.md](user-journeys.md)

---

## 1. Product summary

Nexo is a multi-vendor general-merchandise marketplace:

- Independent vendors sell through one shared storefront.
- Customers discover products across vendors, buy from several vendors in one checkout and pay once.
- Each vendor fulfils its own part of the order.
- The platform collects payment, keeps a commission, and accounts for what it owes each vendor.

Nexo is built as a **portfolio showcase**. It must look, behave and hold up under scrutiny like a credible commercial product. It is not operated as a real business:

- all vendors, customers and orders are fictional;
- payments run in Stripe test mode;
- vendor payouts are simulated.

## 2. Objectives

| # | Objective | Evidence of success |
|---|---|---|
| O1 | Demonstrate correct marketplace commerce | Multi-vendor checkout, split vendor orders, independent fulfilment and refunds, all passing the failure scenarios in `docs/architecture/lifecycles/` |
| O2 | Demonstrate trustworthy money handling | Ledger balances under property and concurrency tests; vendor statements reconcile to orders and refunds |
| O3 | Demonstrate engineering quality | All CI gates green (tests on MySQL, static analysis, types, lint, arch rules); ADRs for every boundary decision |
| O4 | Feel premium | New visual identity (Phase 2); polished storefront, vendor dashboard and admin; real RTL Arabic |
| O5 | Be reproducible | Demo rebuilt from a clean database with one command; documented credentials for each role |

## 3. Audience

| Audience | What they evaluate |
|---|---|
| Recruiters and hiring managers | First impression, polish, a clear README, a live demo with role logins |
| Engineers and reviewers | Architecture, ADRs, tests, concurrency and payment correctness, code quality |
| Project owner | A maintainable codebase that can grow |

## 4. Users and roles

The full permission matrix is in `docs/architecture/security-model.md`.

| Role | Description |
|---|---|
| Visitor | Not signed in. Browses, searches, keeps a guest cart. |
| Customer | Signed-in shopper. Checks out, tracks orders, returns, reviews, wishlist, follows stores, loyalty and referrals. |
| Vendor Owner | Created when the platform approves a vendor application. Full control of their own vendor, including team management. |
| Vendor Manager | Catalogue, inventory, orders, returns, shipping settings, review replies. |
| Vendor Fulfilment | Order fulfilment and stock adjustments. |
| Vendor Finance | Earnings, statements, payouts, refund approval. |
| Vendor Support | Order lookup, return handling, review replies. Read-only on catalogue. |
| Platform Admin | Everything, including staff management and impersonation. |
| Platform Operations | Vendor applications and lifecycle, catalogue moderation, order and return arbitration, promotions. |
| Platform Finance | Commission settings, refunds override, disputes, reconciliation, payout runs. |
| Platform Support | Read access to orders, customers and vendors for support. No money-moving actions. |

## 5. Product surfaces

1. **Customer storefront** (`/`, Vue). Covers:
   - home and merchandising;
   - category browsing;
   - search with facets;
   - product pages with variants;
   - vendor store pages;
   - multi-vendor cart and checkout;
   - account pages: orders, returns, addresses, wishlist, followed stores, points, referrals, reviews, notifications.
2. **Vendor dashboard** (`/vendor`, Vue). Covers:
   - overview;
   - products and variants;
   - inventory;
   - orders and fulfilment;
   - returns;
   - earnings, statement and payouts;
   - reviews;
   - store profile;
   - shipping rates;
   - team;
   - notifications.
3. **Platform administration** (`/admin`, Filament). Covers:
   - vendor applications and vendors;
   - catalogue taxonomy and moderation;
   - orders and returns arbitration;
   - refunds and disputes;
   - finance (ledger, payouts, reconciliation);
   - promotions;
   - loyalty settings;
   - customers;
   - staff;
   - audit log;
   - system health (outbox, queues, webhooks).

## 6. Scope

### 6.1 In scope for v2

| Area | Notes |
|---|---|
| Identity | Registration, login, email verification, password reset, profile, address book; staff 2FA |
| Vendor onboarding | Application → platform review → vendor created (pending) → approved; suspension and reactivation |
| Vendor team | Invite members with roles; one vendor per user |
| Catalogue | Platform-owned category tree and attribute definitions; vendor products with variants (option combinations), images, descriptions; product status (draft, active, inactive); platform moderation (block or unblock) |
| Pricing | Price per variant, optional compare-at price; tax-inclusive (no tax engine) |
| Inventory | On-hand and reserved per variant; reservations with TTL; adjustments; movement history; low-stock indicator |
| Shipping | Per-vendor flat rates by zone (Peninsular Malaysia, East Malaysia); per-vendor free-shipping threshold; manual carrier and tracking entry |
| Cart | Guest and customer cart, multi-vendor, grouped by vendor; merged on login; stock and price revalidation |
| Checkout | Account required; address; per-vendor shipping; coupon; points redemption; single card payment (Stripe test mode, 3-D Secure) |
| Orders | OrderGroup + VendorOrders; customer timeline per vendor; vendor fulfilment states; cancellation rules |
| Returns and refunds | Customer return requests per VendorOrder line; vendor decision; platform arbitration; partial and full refunds; refunds tracked per VendorOrder |
| Disputes | Ingest provider disputes (test mode), hold vendor funds, record evidence, record the outcome |
| Finance | Double-entry ledger, frozen commission, vendor balances (pending, available, held, receivable), statement, weekly simulated payouts, reconciliation |
| Promotions | Platform-run coupon codes and automatic discounts (percentage or fixed, minimum spend, category or vendor targeting, usage limits, date window) |
| Loyalty and referrals | Marketplace-wide points earned on completed vendor orders and redeemed at checkout; referral codes (referee discount, referrer points) |
| Reviews | Verified-purchase product reviews (rating + text), one per purchased line, vendor reply, platform moderation |
| Wishlist and store follow | Customer wishlist; follow vendor stores; followed-store feed on the account page |
| Notifications | Database (in-app) and email for order, return, refund, payout and application events |
| Search | Full-text search with facets: category, price, vendor, rating, in stock. MySQL-backed (ADR-0019) |
| i18n | `en` (primary), `ar` (RTL), `ms` for all UI. Platform taxonomy translated. Vendor-entered content is single-language. |
| Demo | Reproducible seeded environment with role logins |

### 6.2 Out of scope for v2 (with reason)

| Excluded | Reason |
|---|---|
| Guest checkout | Account-required checkout removes a class of ownership bugs; the cart still works for guests (decision P-1, below) |
| Real vendor payouts / Stripe Connect | D-6; simulated payouts only |
| Tax calculation and invoices | ADR-0007; prices are tax-inclusive |
| Multi-currency | ADR-0007 |
| Carrier integrations and shipping labels | Manual tracking entry |
| Vendor-funded promotions | Platform-funded only, which keeps the finance model tight |
| Subscriptions, gift cards, digital downloads, bundles, flash-sale engine, A/B experiments, chat, Q&A, waitlist, abandoned-cart email, AI recommendations | Legacy experimental features; not needed for the showcase objectives |
| Public REST API and mobile apps | Inertia is the only client (ADR-0018) |
| Realtime push (WebSockets) | Polling where needed. Reverb is deferred. |
| Multiple vendors per user | ADR-0011 |
| Translation of vendor-entered content | Single-language listing content |

### 6.3 Product decisions proposed in this PRD (need owner confirmation)

| ID | Proposal | Rationale |
|---|---|---|
| P-1 | Checkout requires an account (inline sign-up on the checkout page); guest browsing and cart allowed | Removes guest-ownership bugs; simpler order access |
| P-2 | Vendor products publish immediately once the vendor is approved; platform moderation is reactive (block or unblock) | Pre-moderation slows demos; reactive moderation still demonstrates control |
| P-3 | Return window of 7 days after delivery; auto-deliver 10 days after shipping when not confirmed | No carrier integration exists |
| P-4 | Reservation TTL of 15 minutes, with one extension while payment is processing | Brief §11 |
| P-5 | Points: earn 1 point per RM 1 of goods on **completed** vendor orders; 100 points = RM 1; redeem up to 20 % of goods subtotal | Award after the return window avoids most clawbacks |
| P-6 | Referral: referee gets RM 10 off a first order over RM 50; referrer gets 500 points when that order completes | Simple and demonstrable |
| P-7 | Default commission 10 %, with category and vendor overrides; minimum payout RM 50; weekly payout run (Monday 09:00 MYT) | Plausible marketplace parameters |
| P-8 | Customer may cancel a VendorOrder only while `awaiting_fulfilment`; afterwards they use a return | Standard practice |

## 7. Success criteria for v2 (release gate)

1. A customer buys products from **three vendors** in one checkout. They see one order with three vendor shipments, tracked independently.
2. Each vendor fulfils independently. One vendor cancels; the customer is refunded for that vendor only, and the other two are unaffected.
3. A customer returns one line from one vendor. The partial refund reverses exactly that line's goods, commission and points, and the vendor statement shows it.
4. A disputed payment holds the affected vendors' funds and resolves according to the dispute rules.
5. A weekly payout run produces simulated payouts that equal each vendor's available balance, and the ledger reconciles.
6. Every scenario in `lifecycles/` passes on MySQL in CI, including the parallel concurrency tests.
7. Every page works in `en`, `ar` (RTL) and `ms`; WCAG 2.2 AA checks pass on the key journeys.
8. The demo rebuilds from an empty database with one command, and the seeded data passes the ledger invariants.

## 8. Assumptions and constraints

- Single developer; phase-gated delivery per the brief.
- Demo scale: about 12 vendors, up to 1,000 products, up to 5,000 seeded orders. Architecture is not designed beyond 10× that without a new ADR.
- Stripe test mode is available for MYR card payments, including 3-D Secure test cards. This is an assumption to verify in Phase 5 (D-14).
- All content (names, addresses, images) is fictional or licensed.

## 9. Open questions

- P-1 to P-8 above.
- D-9, D-10, D-11 in ADR-0016.
- D-15: commit author identity before the repository is public.
