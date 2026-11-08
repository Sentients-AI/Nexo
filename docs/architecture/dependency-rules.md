# Dependency Rules

- **Status:** Draft for owner review (Phase 1). This document is normative once ADR-0010 is accepted. Every rule below becomes a Pest `arch()` test in Phase 3.
- **Date:** 2026-11-01

## 1. Layer rules (inside every module)

```
Http ──► Application ──► Domain
Infrastructure ──► Application, Domain
Filament ──► Application (writes), Application/Queries (reads)
Domain ──► Shared only
```

| # | Rule | Arch test sketch |
|---|---|---|
| L1 | `Domain` does not import `Illuminate\Http`, `Illuminate\Support\Facades\Request`, `Inertia\`, `Filament\`, `Livewire\`, `Stripe\` | `arch()->expect('App\Modules\*\Domain')->not->toUse([...])` |
| L2 | `Application` does not import `Http`, `Filament`, `Inertia` | similar |
| L3 | Controllers do not use `DB::`, `Model::create/update/save/delete` or status setters; they call one Application use case | `expect('App\Modules\*\Http\Controllers')->not->toUse('Illuminate\Support\Facades\DB')` plus a custom expectation |
| L4 | Only state-machine classes assign `status` attributes | custom expectation over `->status =` / `update(['status'` patterns (PHPStan rule if arch is insufficient) |
| L5 | Provider SDKs (`Stripe\`) are used only in `Payments\Infrastructure` | arch |
| L6 | `env()` is used only in `config/` | existing rule |
| L7 | No debug helpers (`dd`, `dump`, `ray`, `var_dump`) | existing preset |

## 2. Module rules

| # | Rule |
|---|---|
| M1 | A module may import another module **only** from `App\Modules\<Other>\Contracts\*`. |
| M2 | Every module may import `App\Shared\*`. `Shared` imports no module. |
| M3 | No Eloquent relationship, join, or raw query from module A touches a table owned by module B. The exceptions are listed in §4. |
| M4 | Allowed synchronous dependencies are only those in the matrix below (✓). Anything else is either an event subscription or a design error. |
| M5 | No cycles in synchronous dependencies. The matrix is acyclic; the arch test computes the graph. |
| M6 | Events are published only by their owning module (`<Module>\Contracts\Events`), and are consumed through explicit subscriptions. |

### Synchronous dependency matrix (row → may call contracts of column)

| ↓ caller / callee → | Identity | Vendors | Catalogue | Inventory | Shipping | Ordering | Payments | Finance | Promotions | Loyalty | Engagement | Search | Notif. |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Identity** | | | | | | | | | | | | | |
| **Vendors** | ✓ | | | | | | | | | | | | |
| **Catalogue** | | ✓ | | | | | | | | | | | |
| **Inventory** | | ✓ | ✓ | | | | | | | | | | |
| **Shipping** | | ✓ | | | | | | | | | | | |
| **Ordering** | ✓ | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓¹ | ✓ | ✓ | | | |
| **Payments** | | | | | | ✓² | | | | | | | |
| **Finance** | | ✓ | | | | ³ | ³ | | | | | | |
| **Promotions** | | | ✓ | | | | | | | | | | |
| **Loyalty** | ✓ | | | | | | | | | | | | |
| **Engagement** | ✓ | ✓ | ✓ | | | ✓⁴ | | | | | | | |
| **Search** | | ✓ | ✓ | ✓ | | | | | | | ✓ | | |
| **Notifications** | ✓ | ✓ | | | | ✓ | | | | | | | |

Footnotes:

1. Ordering asks Finance for the commission rate to freeze (`CommissionRates::resolve(vendorId, categoryId)`), and that is all.
2. Payments calls `Ordering\Contracts\OrderPayments` inside the payment-success transaction (context map §3). **Cycle check:** Ordering → Payments (create attempt, request refund) and Payments → Ordering (markPaid) would form a cycle. It is broken as follows:
   - `OrderPayments` is an interface declared in **Payments\Contracts** and *implemented* by Ordering. This is dependency inversion: Payments owns the port, and Ordering provides the adapter.
   - Payments therefore never imports Ordering, and the graph stays acyclic.
3. **Deliberately empty.** Finance must not call Ordering or Payments synchronously; Ordering → Finance (footnote 1) would otherwise create a cycle.
   - Statements are built from Finance's own ledger. Every ledger transaction carries its `reference_type` and `reference_id`, plus the order and refund references it needs for display, copied from the event payload.
   - Reconciliation and dashboards use the named read-model queries in §4.
4. Engagement asks Ordering "did this customer receive this line?" for verified reviews.

## 3. Event subscriptions (asynchronous; no restriction on direction)

| Event (publisher) | Subscribers |
|---|---|
| `VendorApproved`, `VendorSuspended`, `VendorReinstated` (Vendors) | Catalogue (sellability cache), Search, Notifications |
| `ProductChanged`, `ProductBlocked/Unblocked` (Catalogue) | Search, Engagement (wishlist availability), Notifications (followers, S) |
| `StockLevelChanged` (Inventory) | Search |
| `CheckoutPlaced` (Ordering) | Notifications (none in v2: placeholder for an abandoned-payment reminder) |
| `OrderGroupPaid`, `VendorOrderPaid` (Ordering) | Notifications, Finance (capture posting, from `PaymentCaptured`, see below) |
| `PaymentCaptured`, `ProviderFeeRecorded`, `RefundSucceeded`, `RefundFailed`, `DisputeOpened/Won/Lost` (Payments) | Finance, Ordering (refund status), Loyalty and Promotions (reversals), Notifications |
| `VendorOrderCancelled`, `ReturnReceived` (Ordering) | Payments (request refund), Notifications |
| `VendorOrderCompleted` (Ordering) | Finance (maturity), Loyalty (award), Engagement (review prompt) |
| `PayoutMade` (Finance) | Notifications |
| `ReviewPublished/Hidden` (Engagement) | Search (rating), Notifications |

## 4. Allowed read-model exceptions to M3

These read across tables owned by other modules. Each is a read-only query class in its own module's `Application/Queries`, named and listed here, and arch-tested to use only `DB::table()->select` (no writes).

| Query | Module | Reads | Why |
|---|---|---|---|
| `FinanceDashboardQuery` | Finance | `vendor_orders`, `refunds` | Period aggregates; contract calls would be N+1 |
| `ReconciliationQuery` | Finance | `payment_attempts`, `provider_events`, `refunds` | Matching against the ledger |
| `AdminOrderLookupQuery` | Ordering | `payment_attempts.provider_payment_id`, `users.email` | Support lookup |
| `SearchDocumentBuilder` | Search | `products`, `variants`, `stock_levels`, `vendors`, `reviews` | Rebuild command (the event path uses contracts) |

Adding to this list needs a PR that updates this table.

## 5. Frontend rules

| # | Rule |
|---|---|
| F1 | `resources/js/storefront` and `resources/js/vendor` may import `resources/js/shared`, but not each other |
| F2 | Pages import types only from `types/generated.d.ts` for server data (no local re-declaration) |
| F3 | No money arithmetic in components beyond display; totals come from the server (ADR-0013, ADR-0018) |
| F4 | No physical direction utilities (`ml-`, `pr-`, `left-`, `text-left`) in components; use logical equivalents (`ms-`, `pe-`, `start-`, `text-start`) |

Rules F1–F4 are enforced with ESLint (`import/no-restricted-paths`, plus a custom Tailwind class lint) in Phase 3.

ESLint is not installed yet (owner decision), so F4 is enforced now by a Vitest check, `resources/js/rules/logical-direction.test.ts`, which scans Vue, TypeScript, Blade and CSS for physical utilities and properties. It moves to ESLint if and when ESLint is approved.
