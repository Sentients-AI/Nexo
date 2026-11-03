# System Overview

- **Status:** Draft for owner review (Phase 1)
- **Date:** 2026-11-01

## 1. Context

```
                 ┌──────────────┐     card details (Payment Element; never touch Nexo)
  Customer ─────►│              │◄──────────────────────────────── Stripe.js
  (browser)      │              │
  Vendor team ──►│  Nexo        │── PaymentIntents / Refunds (test mode) ──► Stripe
  (browser)      │  Laravel 13  │◄── signed webhooks ─────────────────────── Stripe
  Platform ─────►│  monolith    │
  staff (/admin) │              │── transactional email ────────────────────► SMTP provider
                 └──────┬───────┘
                        │
              MySQL 8.4 · Redis (queue/cache/locks) · object storage for images
```

## 2. Runtime components

| Component | Role |
|---|---|
| Web (PHP-FPM) | Inertia pages for storefront (`/`) and vendor (`/vendor`); Filament (`/admin`); webhook endpoint (`/webhooks/stripe`); payment-status polling (`/checkout/{ref}/status`) |
| Queue workers | Outbox subscribers, provider-event processing, notifications, search indexing, image processing |
| Scheduler | Outbox relay (5 s), reservation sweeper (1 min), stuck-attempt/refund sweeps (5 min), delivery auto-confirm and completion (hourly), payout run (weekly), reconciliation (nightly), pruning |
| MySQL 8.4 | Single schema; source of truth for everything including the ledger |
| Redis | Queue, cache, rate limiting, scheduler mutexes (demo env) |
| Object storage | Product, review and store images (local disk in dev, S3-compatible in demo) |

## 3. Code organisation (ADR-0010)

```
app/
  Shared/                Money, Clock, PublicId, Outbox, Idempotency, Audit, exceptions
  Modules/
    Identity/  Vendors/  Catalogue/  Inventory/  Shipping/  Ordering/
    Payments/  Finance/  Promotions/  Loyalty/  Engagement/  Search/  Notifications/
      ├─ Domain/  Application/  Infrastructure/  Http/  Contracts/  Filament/
resources/js/
  storefront/  vendor/  shared/ (design-system components, lib/, i18n)
  types/generated.d.ts   (ADR-0018)
tests/
  Unit/  Feature/  Isolation/  Concurrency/  Arch/
```

## 4. Key flows (detail in `lifecycles/`)

| Flow | Path |
|---|---|
| Checkout | Ordering.PlaceCheckout → (TX: OrderGroup + VendorOrders + Inventory.reserve + Promotions.hold + Loyalty.hold + outbox) → Payments.CreateAttempt → provider |
| Payment | webhook → Payments.provider_events → job → TX: attempt succeeded + Ordering.markGroupPaid (+ Inventory.consume, Promotions.confirm, Loyalty.confirm) + outbox → Finance posts capture; Notifications; Search |
| Fulfilment | Vendor UI → Ordering transitions → outbox → Notifications; completion → Finance maturity, Loyalty award |
| Refund | Ordering (cancel/return) or Finance staff → Payments.RequestRefund → provider → webhook → Finance posts refund; Loyalty/Promotions adjust |
| Payout | Scheduler → Finance.RunPayouts (idempotent) → ledger postings → Notifications |

## 5. Cross-cutting concerns

| Concern | Approach |
|---|---|
| AuthN | Laravel session auth (Fortify-style flows built in-app or via starter kit — chosen in Phase 3); staff 2FA |
| AuthZ | Policies + permission enum + VendorContext (ADR-0011) |
| Transactions | Use cases own boundaries; no provider calls inside DB transactions (ADR-0015) |
| Async | Outbox + inbox (ADR-0017) |
| Idempotency | Unique keys + idempotency store (ADR-0015) |
| Time | Injected `Clock`; UTC storage; MYT display |
| Observability | JSON logs with correlation id, health endpoint, admin health page |
| i18n | Laravel lang JSON as single source; exported to the frontend at build; RTL via logical CSS |
| Security headers | Middleware; CSP with Vite nonce |

## 6. What is deliberately absent

Microservices, message brokers, CQRS/event sourcing beyond the outbox and ledger, a public REST API, WebSockets, Typesense, Stripe Connect — each with its reason in the relevant ADR or PRD §6.2.
