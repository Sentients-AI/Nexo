# Dependency Rules

- **Status:** Normative (ADR-0010, accepted 2026-11-15 under M6-D-04). Each rule is either enforced by an architecture test in `tests/Arch/` (M1 Slice B, delivered as M6.1, #23), proven by a deliberate-violation fixture, or deferred with a named trigger (§6). How the tests read the rules: L2's `Http` and `Filament` are the module's own delivery layers as well as the Inertia and Filament packages; L3's tested part is `Illuminate\Support\Facades\DB` in `<Module>\Http\Controllers`, and the global `DB` alias falls to its deferred method-call part; a rule on a package that is not installed yet (Stripe, Filament, Livewire) takes effect when Composer installs it.
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
| L3 | Controllers do not use `DB::`, `Model::create/update/save/delete` or status setters; they call one Application use case | `expect('App\Modules\*\Http\Controllers')->not->toUse('Illuminate\Support\Facades\DB')`. The method-call part is deferred (§6) |
| L4 | Only state-machine classes assign `status` attributes | PHPStan rule `Tests\Arch\PHPStan\VendorStatusWriteRule`, registered in `phpstan.neon.dist`: only `VendorLifecycle` writes a Vendor's status. It reports property, offset and `setAttribute` writes, and a `status` key passed to Eloquent or query-builder writes on `Vendor`, its builder or the `vendors` table. Its fixtures run in the Arch suite. It sees only statically known writes: not a non-constant array, a column or table name in a variable, raw SQL, or code outside PHPStan's paths (tests). An ignore comment without an identifier also hides a report; `code-quality.md` forbids those, but nothing enforces it yet. The next state machine adds its model to the rule |
| L5 | Provider SDKs (`Stripe\`) are used only in `Payments\Infrastructure` | arch |
| L6 | `env()` is used only in `config/` | existing rule |
| L7 | No debug helpers (`dd`, `dump`, `ray`, `var_dump`) | existing preset |

## 2. Module rules

| # | Rule |
|---|---|
| M1 | A module may import another module **only** from `App\Modules\<Other>\Contracts\*`. |
| M2 | Every module may import `App\Shared\*`. `Shared` imports no module. |
| M3 | No Eloquent relationship, join, or raw query from module A touches a table owned by module B. The exceptions are listed in §4. Deferred (§6). |
| M4 | Allowed synchronous dependencies are only those in the matrix below (✓). Anything else is either an event subscription or a design error. |
| M5 | No cycles in synchronous dependencies. The matrix is acyclic; the arch test computes the graph. |
| M6 | Events are published only by their owning module (`<Module>\Contracts\Events`), and are consumed through explicit subscriptions. Deferred (§6). |

### Synchronous dependency matrix (row → may call contracts of column)

| ↓ caller / callee → | Identity | Vendors | Catalogue | Inventory | Shipping | Ordering | Payments | Finance | Promotions | Loyalty | Engagement | Search | Notif. |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Identity** | | | | | | | | | | | | | |
| **Vendors** | ✓ | | | | | | | | | | | | |
| **Catalogue** | | ✓ | | | | | | | | | | | |
| **Inventory** | | ✓ | ✓ | | | | | | | | | | |
| **Shipping** | | ✓ | | | | | | | | | | | |
| **Ordering** | ✓ | ✓ | ✓ | ✓ | ✓ | | ✓² | ✓¹ | ✓ | ✓ | | | |
| **Payments** | | | | | | | | | | | | | |
| **Finance** | | ✓ | | | | ³ | ³ | | | | | | |
| **Promotions** | | | ✓ | | | | | | | | | | |
| **Loyalty** | ✓ | | | | | | | | | | | | |
| **Engagement** | ✓ | ✓ | ✓ | | | ✓⁴ | | | | | | | |
| **Search** | | ✓ | ✓ | ✓ | | | | | | | ✓ | | |
| **Notifications** | ✓ | ✓ | | | | ✓ | | | | | | | |

Footnotes:

1. Ordering asks Finance for the commission rate to freeze (`CommissionRates::resolve(vendorId, categoryId)`), and that is all.
2. **Payments → Ordering is not a dependency.** Ordering uses `Payments\Contracts` (create an attempt, request a refund) and implements the port `Payments\Contracts\OrderPayments`, which Payments declares (ADR-0010 §4, ports). In the payment-success transaction (context map §3), Payments calls that port; Ordering's implementation is bound in Ordering's service provider.
   - Ordering → Payments is therefore the only edge between them, and the graph stays acyclic.
   - Payments never imports any `App\Modules\Ordering` namespace.
3. **Deliberately empty.** Finance must not call Ordering or Payments synchronously; Ordering → Finance (footnote 1) would otherwise create a cycle.
   - Statements are built from Finance's own ledger. Every ledger transaction carries its `reference_type` and `reference_id`, plus the order and refund references it needs for display, copied from the event payload.
   - Reconciliation and dashboards use the named read-model queries in §4.
4. Engagement asks Ordering "did this customer receive this line?" for verified reviews.

### Composition root and transitional legacy (ADR-0010 §2a, §2b)

| # | Rule |
|---|---|
| C1 | No `App\Modules\*` or `App\Shared\*` class imports `App\Providers`, `App\Http` or `App\Models`. The only exception is Identity importing `App\Models\User` until its exit milestone (M3). |
| C2 | `App\Models` contains only `User`, and `App\Http\Controllers` contains only `Controller`. The allowlist is closed; each exit removes its entry in the same change. |
| C3 | `App\Modules` contains only the 13 modules in the matrix above. Each contains only `Domain`, `Application`, `Infrastructure`, `Http`, `Contracts` and `Filament`, and each directory exists only once its first class is written. |

The composition root (`App\Providers`, kernel-level `App\Http`, `bootstrap/`, `routes/`) may import `App\Shared`, module `Contracts`, and module `Http`, `Filament` or `Infrastructure` classes only to register them.

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

ESLint is not installed (owner decision). F2 and F3 are deferred until M3 (§6). F1 and F4 are enforced by these Vitest checks, which run in CI, and move to ESLint only if ESLint is approved:

- **F1:** `resources/js/rules/surface-boundaries.test.ts`. Storefront code is `resources/js/storefront/**` and `resources/js/pages/Storefront/**`; vendor code is `resources/js/vendor/**` and `resources/js/pages/Vendor/**`; everything else (`shared/`, `i18n/`, `design/`, `types/`, …) is shared and may import neither. It resolves `@/` and relative imports, static and dynamic.
- **F4:** `resources/js/rules/logical-direction.test.ts`, which scans Vue, TypeScript, Blade and CSS for physical utilities and properties.

## 6. Deferred rules

These rules are normative but not yet enforced, because the code they constrain does not exist or the enforcement mechanism needs its own change. Each is enforced, with a deliberate-violation fixture, no later than its trigger. The architecture-test registry in `tests/Arch/` lists the same rules and triggers.

| Rule | Why deferred | Trigger | Expected milestone |
|---|---|---|---|
| L3, method-call part (Eloquent writes and status setters in controllers) | Arch tests cannot see method calls; needs a PHPStan rule | First module with controllers and Eloquent models | M3 |
| M3 (no relationship, join or raw query on another module's table) | Needs tables and the table-ownership map in `database-design.md` | Second module that owns tables | M3–M4 |
| M6 (events published only by their owning module) | No events exist; ADR-0017 is Proposed | ADR-0017 accepted and the first integration event | Outbox milestone; M3 at the earliest (`VendorApproved`) |
| F2 (pages import server-data types only from `types/generated.d.ts`) | ADR-0018 is Proposed; no generated types exist | First typed page contract | M3 |
| F3 (no money arithmetic in components beyond display) | No server money is rendered yet | First page that renders server money | M3 |
