# Nexo 2.0 — Phase 0: Discovery and Technical Assessment

| | |
|---|---|
| **Status** | Draft for owner review |
| **Date** | 2026-11-01 |
| **Scope** | Read-only assessment of the existing codebase. No application code, configuration, migrations or dependencies were changed. |
| **Repository assessed** | `AdilAzhari/nexo-ecommerce` → local `C:\laragon\www\modular-ecommerce-system` (its `origin` remote is `Sentients-AI/nexo-ecommerce`) |
| **Working tree assessed** | Branch `redesign` @ `774d131`, **plus 44 uncommitted files** (33 modified, 11 untracked). `redesign` is 4 commits ahead of / 2 behind `main`. |
| **Also inspected** | `C:\laragon\www\Nexo` (`Sentients-AI/Nexo`): a separate, earlier restart on Laravel 13 + React 19 (see §16, Q2) |

### How to read this report

Every finding is tagged with:

- **Severity:** Critical / High / Medium / Low / Info.
- **Kind:**
  - **Defect:** behaviour is wrong today.
  - **Risk:** an architectural concern that will cause defects.
  - **Opportunity:** an improvement, not a fix.
- **Evidence level:**
  - **Verified:** executed or observed (tests, type-check, command output).
  - **Code-confirmed:** traced through source, not executed.
  - **Documented:** claimed by docs and not verified.
  - **Assumption:** an inference that still needs confirming.

All paths are relative to the repository root unless stated otherwise.

---

## 1. Executive summary

**What exists.** The repository is a large Laravel 12 + Filament 5 + Vue 3/Inertia 2 application:

- 621 PHP files in `app/` across 29 `app/Domain/*` folders.
- 109 migrations.
- 81 Vue single-file components.
- ~1,314 Pest test cases in 135 files.

It was built as a **multi-tenant SaaS** (one isolated store per subdomain). On 2026-08-30 it was partly re-documented as a **single multi-vendor marketplace** for the Sudan market (`docs/product/*`, ADR-0002..0004). The code still implements the SaaS model. The marketplace pivot exists on paper plus a small WIP on `redesign`, and that WIP takes a direction (a session-scoped "shopping tenant") that contradicts its own ADR-0004.

**The headline.** The codebase *looks* far more sophisticated than it *behaves*. It has DDD folder structure, specifications, guards, value-object IDs, a domain-event table, projections, metrics and an `INVARIANTS.md`. But several load-bearing paths are broken or never wired:

1. **Payments never mark an order as paid** (Critical, code-confirmed).
   - No production code dispatches `PaymentSucceeded`, so the `MarkOrderPaid` listener never runs.
   - The webhook success path also calls Stripe's *confirm* API on a PaymentIntent the browser has already confirmed. Stripe rejects that call, and the job then fails and rolls back.
2. **The domain-event "outbox" is write-only** (Critical, code-confirmed). `DomainEventRecorder::record()` writes to `domain_events`. Nothing ever schedules or dispatches the job that reads it (`DispatchDomainEventsJob`). As a result:
   - `RefundSucceeded` never reaches `UpdateOrderRefundState` or `ReleaseStockOnRefund`.
   - `PaymentFailed` never reaches its listeners.
3. **Inventory arithmetic is internally inconsistent** (Critical, code-confirmed).
   - Reserving stock subtracts it twice: it decrements `quantity_available` *and* `isAvailable()` subtracts `quantity_reserved` again.
   - Release and cancel paths ignore `variant_id`, and use three different, contradictory semantics.
4. **Vendor dashboard has no role authorization** (Critical, code-confirmed).
   - Any logged-in user whose `tenant_id` matches the store can use `/vendor/*`. Storefront customers get the store's `tenant_id` automatically on registration.
   - Such a user can set any order to any status (bypassing the state machine), edit products and prices, and adjust stock.
5. **Staging deploys the `redesign` branch against the production database** (High, documented in the workflow itself). This conflicts directly with the brief's rule never to connect experiments to production.
6. **The "concurrency/torture" tests do not test concurrency.** They run sequentially on SQLite, where `lockForUpdate()` is a no-op, and their assertions are loose enough to pass with the double-subtraction bug present.

**Assessment.** The *ideas* are mostly sound and worth keeping: integer-cents money, idempotency keys, snapshotted order lines, a state-machine enum, an `OrderGroup` + per-vendor `Order` split (ADR-0004) and context-dependent vendor scoping (ADR-0003). The *implementation* is not a safe base to evolve. The money, inventory and authorization paths need re-implementation against a fresh schema, not patching. This supports the brief's hybrid-rebuild strategy:

- Reuse concepts, enums, value objects, specifications and selected UI pieces as references.
- Re-implement the transactional core with real tests (MySQL, real concurrency).

**The single most important owner decision (Q1)** is the product context. The ratified docs target the **Sudan market with Stripe + Stripe Connect**. The new brief targets a **portfolio-grade, Lazada/Shopee-style general marketplace (EN/AR/MS)**. Stripe does not support Sudan-based businesses or Connect accounts *(assumption, to verify against Stripe's current country list)*, so the documented V1 money flow cannot work for the documented market. This decision shapes ADR-0005 (payments), currency, demo data and copy.

---

## 2. Repository inventory

### 2.1 Stack (verified from `composer.json`, `package.json`, `composer.lock`)

| Layer | Declared | Notes |
|---|---|---|
| PHP | `^8.2` (README says 8.4; CI uses 8.4; local CLI is 8.5.8) | Constraint is looser than reality |
| Laravel | `^12.0` | |
| Admin | `filament/filament ^5.3` | Single panel `control-plane` (`app/Providers/Filament/AdminPanelProvider.php`) |
| Frontend | Vue 3.5, `@inertiajs/vue3 ^2.3`, TypeScript 5.9, Tailwind 4, Vite 7 | `vue-tsc` installed but **not run in CI** |
| Payments | `laravel/cashier ^16.1` **and** `stripe/stripe-php: "*"` (unpinned) | Cashier used only for the (dormant) subscription feature |
| Realtime | `laravel/reverb: "*"` (unpinned), laravel-echo, pusher-js | |
| Search | `laravel/scout ^11.1`, `typesense/typesense-php ^6.0` | |
| Auth | Sanctum 4, Socialite (Google) | |
| Misc | `nativephp/electron: "*"`, `amphp/parallel`, `barryvdh/laravel-dompdf`, `fakerphp/faker` (in **require**, not dev) | `nativephp/electron` has no visible use in a web marketplace; unpinned `*` constraints are a supply-chain/reproducibility risk |
| Quality | Pint, Rector, `carthage-software/mago 1.1.0`, Pest 4, `laravel/boost` | **No PHPStan/Larastan.** Mago is installed but not wired into CI |
| Composer audit | `config.audit.ignore: GHSA-vvj3-c3rp-c85p` | Ignored advisory must be justified (Q9) |

### 2.2 Top-level layout

```
app/Application/   UseCases (Checkout, ProcessPayment, RequestRefund) + Request/Response DTOs
app/Domain/        29 folders (see §4)
app/Infrastructure/Payment/Stripe/PaymentGatewayService.php   (only infrastructure adapter)
app/Filament/      ~23 resources, 8 pages, 17 widgets
app/Http/          36 web controllers, 17 API v1 controllers, 9 middleware, 10 policies
app/Shared/        Alerting, Metrics, Performance, Specifications, ValueObjects, Domain events, AuditLog
app/Jobs/          HandleStripeEventJob, ConfirmPaymentIntentJob, DispatchDomainEventsJob
resources/js/      Pages (49), Components (29), Composables (13), Layouts (3), types (4)
lang/{en,ar,ms}/ui.php   117 lines each
tests/             Feature (Api, Domains, Journeys, MultiTenancy, Torture, Web, Filament…), Unit, Browser (3)
.github/workflows/ ci.yml, pr-checks.yml, format.yml, codeql.yml, deploy.yml, deploy-staging.yml
```

### 2.3 Documentation inventory and its reliability

| Document | Describes | Reliability |
|---|---|---|
| `README.md` | Feature list (≈40 features), SaaS multi-tenancy | **Overstates.** It lists e.g. "Order Processing — full lifecycle (pending → paid → …)", which the payment defect breaks (§7.1). The 2026-08-30 banner admits it is mid-pivot. |
| `docs/product/{vision,goals,personas,problem-statement,scope}.md` | Sudan-market marketplace, Stripe Connect, V1 success criteria | Coherent, but is *intent*. Contradicted by the new brief on market and audience. Untracked (uncommitted). |
| `docs/architecture/adr/0002..0004` | Vendor = tenant record; explicit vendor scoping; OrderGroup + per-vendor Order | Status "Accepted" but **not implemented**. ADR-0005 (payments) referenced, never written. Untracked. |
| `docs/architecture/marketplace-migration.md` | Phases A–G of SaaS → marketplace pivot | Plan only |
| `INVARIANTS.md` | Invariants, state machines, guards | Partly aspirational. Its own "Known Gaps" section admits incomplete guards and a webhook bug. Claims "Order Pending → Paid via `PaymentSucceeded` event via webhook", which is not wired (§7.1). |
| `PRODUCTION_READINESS_REVIEW.md`, `DATABASE_SCHEMA.md`, `API.md`, `docs/COMPREHENSIVE_CODEBASE_GUIDE.md`, `docs/DECISIONS.md`, `docs/ARCHITECTURE_DECISIONS.md` | SaaS-era deep dives | Out of date relative to the pivot. Treat as historical. |
| `docs/REDESIGN_BRIEF.md`, `redesign.md`, `Check.md` | Earlier visual-redesign briefs ("Nile Market" palette) | Superseded by the Nexo 2.0 brief |
| `CLAUDE.md` (git-ignored) | Agent instructions | Not part of the shared repo |

---

## 3. Current architecture

### 3.1 Runtime shape

```
Browser (Vue/Inertia SPA)            Filament /control-plane (Livewire)
   │  Inertia pages  /{locale}/…        │
   │  JSON  /api/v1/* (session+Sanctum) │
   ▼                                    ▼
routes/web.php ─ middleware: locale, tenant.subdomain | tenant.user, auth
routes/api/v1.php ─ api group + EnsureFrontendRequestsAreStateful + ResolveTenantFromUser
   │
Controllers ──(some)──► Application/UseCases ──► Domain/*/Actions ──► Eloquent models
   │ (48 of ~60 controllers query Eloquent directly)
   ▼
MySQL/MariaDB ◄── Context('tenant_id') global TenantScope on 46 models
Queue: HandleStripeEventJob; Scout→Typesense; Reverb broadcasts; Schedule: 3 commands
```

### 3.2 Layering in practice

- **Application layer is thin and partial.** Only three use cases exist: `CheckoutUseCase`, `ProcessPaymentUseCase` and `RequestRefundUseCase`. Most behaviour lives in controllers or Filament pages calling models or actions directly.
- **Domain is not independent of infrastructure.** Domain models are Eloquent models, and actions call `DB::transaction`, `Context`, `config()` and `MetricsRecorder` directly. 11 domain files import `App\Events\*` (broadcast events defined outside the domain). This is normal for Laravel, but it is not the "domain independent of infrastructure" the brief asks for.
- **Two parallel event mechanisms:**
  1. Laravel events (`X::dispatch`, auto-discovered listeners in `app/Domain/*/Listeners`, see `bootstrap/app.php`).
  2. `DomainEventRecorder` → `domain_events` table, which has no consumer (§7.2).

  Which mechanism is used varies per action, and that is the root cause of several defects.
- **Duplicated actions:**
  - `Inventory/Actions/ReserveStock` and `ReserveStockAction` are near-identical, but only one filters by variant.
  - `Order/Actions/CheckoutAction` is used only by tests.
  - `Payment/Webhooks/HandlePaymentWebhookAction.php` is empty.

### 3.3 Domain dependency graph (code-confirmed, from `use App\Domain\…` imports)

```
Order    -> Bundle Cart Currency GiftCard Inventory Payment Product Promotion Refund Shipping Tax Tenant User   (14)
Product  -> Category Inventory Order Question Review Tenant User
Refund   -> Inventory Order Payment Tenant User
Payment  -> Order Tenant
Inventory-> Order Product Tenant User
Promotion-> Cart Category Order Product Tenant User
Tenant   -> Product Review Role User
User     -> Order Role Tenant
```

**Cycles:**

- Order↔Payment
- Order↔Inventory
- Order↔Refund
- Order↔Product
- Order↔Promotion
- Product↔Inventory
- Tenant↔User

No tooling enforces boundaries: no architecture tests, no deptrac, no PHPStan rules.

---

## 4. Current domain map

| Folder | Files | Scope tag in `docs/product/scope.md` | Observed state |
|---|---|---|---|
| Refund | 32 | Core | Rich model, broken event delivery (§7.2), race conditions (§7.5) |
| Order | 29 | Core | State-machine enum good; bypassed in several places (§7.4) |
| Inventory | 23 | Core | Arithmetic inconsistent (§7.3) |
| Product | 22 | Core | Variants, price history, downloads |
| Payment | 22 | Core | Success path broken (§7.1) |
| Promotion | 18 | Extended | BOGO/tiered/bundle/flash/code + experiments |
| User | 15 | Core | Is tenant-scoped (`BelongsToTenant`) |
| Review, Referral, Loyalty | 13/13/12 | Extended | |
| Cart | 12 | Core | One cart = one tenant |
| Chat, Question, GiftCard, Subscription, Bundle, Content, FeatureFlag, Config | 2–9 each | Experimental/Extended | Candidates for retirement |
| Projections | 7 | — | Refund/order-financial projections + replay; depends on the unconsumed event table |
| Tenant | 5 | Core ("Vendor") | Subdomain resolution + global scope |
| Tax, Shipping, Currency, Idempotency, Role, Notification, Category, Shared | 1–7 each | Core/Extended | |

**Key observation.** "Domain" here means *folder*, not *bounded context*. Ownership of state transitions is spread across controllers, Filament pages, listeners and actions. For example, `Order.status` is written from:

- `VendorOrderController::updateStatus`
- `CancelOrder`
- `CompensateStockOnCancelAction`
- `HandleStripeEventJob::handleFailure`
- `MarkOrderPaid`
- `RetryOrderFinalizationAction`
- Filament `ViewOrder`

---

## 5. Current database design

### 5.1 Overview (code-confirmed from `database/migrations/*`)

- 109 migrations. 41 of them alter existing tables, and many are "add_x_to_y" patches. This is a normal history, but it is a strong signal for the brief's fresh-schema decision.
- Money is stored as **integer cents** (`bigInteger …_cents`). This is good and should be kept. `decimal` is used only for `exchange_rate (10,6)`, tax `rate (5,4)` and metrics.
- `tenant_id` was retro-fitted to ~45 tables by one migration, `2026_02_07_000002_add_tenant_id_to_tables.php`. It is nullable, with `cascadeOnDelete`.

### 5.2 Integrity findings

| # | Finding | Evidence | Sev / Kind |
|---|---|---|---|
| D1 | Deleting a **tenant cascades** to orders, payment intents, refunds, stock movements and every other tenant-owned table. A vendor deletion would destroy financial history. | `add_tenant_id_to_tables.php` (`->cascadeOnDelete()`) | High / Risk |
| D2 | Deleting a **user cascades** to their orders; deleting a **product cascades** to historical `order_items`. | `create_orders_table.php:18`, `create_order_items_table.php:19` | High / Risk |
| D3 | `stocks`: unique only on `variant_id`. Product-level stock rows (`variant_id NULL`) have no uniqueness, so duplicates are possible. Lookups use `->first()`, so which row is used is non-deterministic. | `relax_product_id_unique_constraint_on_stocks_table.php` | High / Defect-enabling |
| D4 | No DB-level guarantee that `quantity_reserved ≤ quantity_available`, or that either is ≥ 0. `unsigned()` exists on MySQL only; tests run on SQLite. | `create_stocks_table.php` | Medium / Risk |
| D5 | `payment_intents.provider_reference` is neither unique nor indexed, yet it is the webhook lookup key. | `create_payment_intents_table.php` | Medium / Risk |
| D6 | `stock_movements` has no idempotency key (e.g. unique `(stock_id, type, reference_type, reference_id)`), so duplicated movements are possible. | `create_stock_movements_table.php` | Medium / Risk |
| D7 | `refunds` has no idempotency key and no unique `external_refund_id`. `payment_intent_id` is a free string (Stripe id), not an FK. | `create_refunds_table.php`, `make_payment_intent_id_nullable_on_refunds.php` | Medium / Risk |
| D8 | `idempotency_keys.key` is **globally unique** *and* `(key, action)` is unique. Two users choosing the same key collide. | `create_idempotency_keys_table.php` | Low / Defect |
| D9 | Product `slug` is unique per `(tenant_id, slug)`, but the storefront route `/{locale}/products/{product:slug}` is marketplace-wide. Two vendors with the same slug make the route ambiguous. | `add_tenant_id_to_tables.php:54-55`, `routes/web.php` | Medium / Defect (marketplace) |
| D10 | Categories are tenant-owned (`(tenant_id, slug)`). A marketplace needs a platform-owned taxonomy. | same | Medium / Model mismatch |
| D11 | `orders` has no `order_group_id`, `commission_*` or transfer fields. ADR-0004 is unimplemented. | `create_orders_table.php` + later alters | — / Gap |

### 5.3 Implications of a fresh database (brief §3)

**Benefits:**

- Removes ~109 migrations of accreted patches.
- Allows vendor ownership (`vendor_id`), platform-owned taxonomy, `order_groups`, a ledger, `RESTRICT` FKs on financial tables and CHECK constraints from day one.

**Costs:**

- No continuity with `store.aljebal-albeedos.com`. Its users, vendors, orders and Stripe objects are not migrated.
- If that site stays live on the old code, it is a separate product. It must keep its own database, and the new app must never point at it (see R5).
- Any future data migration would be an explicit, separate project with its own ADR.

---

## 6. Frontend assessment

| Area | Finding | Evidence | Sev |
|---|---|---|---|
| Structure | Reasonable Inertia layout: `Pages/`, `Components/{UI,Layout,Products,…}`, `Composables/`, `Layouts/{Guest,Authenticated,Vendor}`. Every SFC uses `lang="ts"`. | `resources/js/**` | Info |
| Type safety | **`vue-tsc --noEmit` reports 22 errors** (TS2339 ×14, TS18046 ×4, TS2322 ×3, TS2353 ×1), mostly in `Pages/Products/Show.vue` (10), `Vendor/Dashboard.vue` (4) and `Orders/Show.vue` (3). CI never runs it. | Verified, run locally 2026-11-01 | Medium |
| Contracts | Types in `resources/js/types/{models,api}.ts` are hand-written. Controllers build ad-hoc arrays (e.g. `CheckoutController::summary`) with no generated or validated contract, and money is passed as raw ints with inconsistent naming (`price`, `subtotal`, `total_cents`). | `app/Http/Controllers/Web/CheckoutController.php` | Medium |
| Design system | Component kit is minimal (Alert, Badge, Modal, Toast, DataTable, EmptyState, Spinner…). No tokens doc. `resources/css/app.css` is 253 lines. The largest pages are monoliths: `Products/Show.vue` 1,370 lines, `Checkout/Summary.vue` 603. | | Medium |
| i18n | `vue-i18n` is created with **empty messages**. Translations come via the Inertia shared prop `translations` from `lang/*/ui.php` (117 lines/locale). 174 `t()` call sites, but many hard-coded English strings remain (e.g. `Pages/Refunds/Status.vue:84`). | `resources/js/i18n/index.ts`, `HandleInertiaRequests.php:64` | Medium |
| RTL | `<html dir>` is set correctly for `ar`. But layout uses **201 physical direction utilities** (`ml-/mr-/pl-/pr-/left-/right-`) vs **1 logical** (`ms-/me-/ps-/pe-/start-/end-`), so Arabic layout will mirror incorrectly. | `resources/views/app.blade.php:2`; grep | High (for an Arabic-first product) |
| Payments UI | Uses Stripe Payment Element + client-side `confirmPayment` (`Composables/usePayments.ts:91`), which is correct. But it then calls the server `/api/v1/checkout/confirm-payment`, which re-confirms server-side (§7.1). | | Linked to C1 |
| Vendor UI | 19 vendor pages exist (dashboard, orders, products CRUD/import, inventory, returns, Q&A, reviews, promotions, storefront, settings, bundles, earnings, analytics, customers) | `Pages/Vendor/*` | Info: good feature inventory to re-spec |

**Verdict.** Treat the frontend as a **feature and requirements reference**, not a base. A new design system (Phase 2) plus logical-property RTL plus a typed props contract will touch nearly every file anyway.

---

## 7. Critical business-flow findings (payments, inventory, orders, refunds)

### 7.1 C1 — Successful payments never transition orders to Paid · **Critical · Defect · Code-confirmed**

**Trace:**

1. The browser confirms the PaymentIntent with Stripe.js (`resources/js/Composables/usePayments.ts:91`).
2. Stripe sends `payment_intent.succeeded` → `StripeWebhookController` → `HandleStripeEventJob::handleSuccess()`.
3. `handleSuccess` calls `ConfirmPaymentIntentAction::execute()`. That calls `Infrastructure/Payment/Stripe/PaymentGatewayService::confirmIntent()`, which calls `$stripe->paymentIntents->confirm()` **on an intent that already succeeded**. Stripe rejects confirming a succeeded intent *(assumption, Stripe API behaviour; verify with a test-mode call)*.
4. The action catches the error, sets `status = Failed` and rethrows *inside* `DB::transaction`. The write rolls back and the job fails.
5. Even if step 3 succeeded, the action only sets `PaymentIntent.status = Succeeded`. **Nothing in `app/` dispatches `PaymentSucceeded`** (grep shows only the class definition, a listener type-hint and tests). So `Order/Listeners/MarkOrderPaid` never runs.

**Further problems:**

- `/api/v1/checkout/confirm-payment` (auth-only) has the same re-confirm problem. Guests have no equivalent.
- `payments:reconcile-orphaned` (`ReconcileOrphanedPaymentsCommand`) would repair orders, but it is **not scheduled** (`routes/console.php` schedules only loyalty expiry, abandoned carts and FX rates). It also only matches intents already marked `Succeeded`, which step 4 prevents.
- **Test gap:** no test exercises the webhook path (`grep HandleStripeEventJob|webhooks/stripe tests/` returns nothing).

**Impact:**

- Customers are charged while orders stay `pending`.
- Vendors filter by status and do not see the order as paid.
- Stock stays reserved.

*Note:* recent commits on `main` ("return the actual client_secret…", "uncaught ModelNotFoundException on checkout…") suggest the live checkout was being debugged in this area.

### 7.2 C2 — Domain-event outbox has no consumer · **Critical · Defect · Code-confirmed**

- `App\Shared\Domain\DomainEventRecorder::record()` inserts into `domain_events`.
- `App\Jobs\DispatchDomainEventsJob` would replay unprocessed rows as Laravel events, but **nothing dispatches or schedules it**.
- Events recorded only this way (`ProcessRefundAction`, `RequestRefundAction`, `RetryRefundExecutionAction`, `HandleStripeEventJob::handleFailure`):
  - `RefundSucceeded` → listeners `UpdateOrderRefundState` and `ReleaseStockOnRefund` **never run**.
  - `RefundFailed` and `RefundRequested` likewise never reach listeners.
  - `PaymentFailed` → `Payment/Listeners/PaymentFailed` **never runs**.
- Even if scheduled, the job is unsafe:
  - No row locking or claim (`SELECT … FOR UPDATE SKIP LOCKED`), so concurrent workers double-dispatch.
  - It marks a row processed after `event()`, so a listener exception leaves the row to be re-dispatched forever, with no attempts counter or dead-letter.
  - `new $eventClass(...$record->payload)` depends on `get_object_vars` ordering and includes base-class properties.

### 7.3 C3 — Inventory arithmetic is inconsistent · **Critical · Defect · Code-confirmed**

| Operation | `quantity_available` | `quantity_reserved` | Implied meaning of `available` |
|---|---|---|---|
| `Stock::isAvailable()` (`Inventory/Models/Stock.php`) | uses `available − reserved` | | on-hand (gross) |
| `ReserveStock` / `ReserveStockAction` | **−qty** | +qty | net of reservations |
| `CompensateStockOnCancelAction` | +qty | −qty | net |
| `ReleaseStockAction` (used by `CancelOrder` and `ReleaseStockOnRefund`) | unchanged | −qty | gross |
| Payment success / fulfilment | *(nothing consumes reservations)* | | — |

**Consequences:**

- **Phantom stockouts.** With 10 units, reserving 3 leaves `available=7, reserved=3`, so sellable is 4. A second reservation of 3 leaves 4/6, so sellable is −2. Only 6 of 10 units can ever be sold.
- **Stock never returns.** `CancelOrder` decrements `reserved` but never restores `available`.
- **Variant blindness.**
  - `ReleaseStockAction` and `CompensateStockOnCancelAction` look up `Stock` by `product_id` only.
  - `CreateOrderFromCart::lockAndValidateStock()` and `ReserveStock` add the variant filter only when `variant_id !== null`. A product-level cart line can therefore lock or modify *any* variant's row.
  - `ReserveStockAction` (used by `CheckoutAction`) never filters by variant at all.
- **No reservation expiry.** Unpaid or abandoned checkouts hold stock forever. No scheduled release exists.
- **No deadlock discipline.** Rows are locked in cart-item order, not sorted by id. Concurrent multi-item checkouts can deadlock.
- **The "torture" tests miss all of this.** `tests/Feature/Torture/ConcurrentStockReservationTest.php` runs a sequential `for` loop on SQLite, where `lockForUpdate()` is a no-op. Its assertions (`reserved ≤ initial`, `available ≥ 0`) pass even with double subtraction.

### 7.4 C4 — Order state machine is bypassed · **High · Defect · Code-confirmed**

- `OrderStatus::canTransitionTo()` and `Order/Support/OrderStateGuard` exist and are reasonable.
- `VendorOrderController::updateStatus()` does `$order->update(['status' => $validated['status']])` for **any** enum value: `paid`, `refunded`, back to `pending`, and so on.
- `CancelOrder` only refuses `cancelled`/`completed`. It cancels paid or shipped orders without a refund and without a guard.
- `HandleStripeEventJob::handleFailure()` sets `Order → Failed` on the first `payment_intent.payment_failed`. In Stripe that event is **not terminal**: the customer can retry with another card on the same intent. Because `PaymentStatus::Failed` is terminal locally, a later `succeeded` webhook is ignored. The result is a charged customer with a failed order, and **no stock release** on failure.
- `OrderStatus::PartiallyRefunded → PartiallyRefunded` is disallowed, so a second partial refund cannot be represented.
- No row lock or optimistic version on status writes, so concurrent transitions are last-write-wins.

### 7.5 C5 — Refund races and unsafe provider calls · **High · Defect/Risk · Code-confirmed**

- `RequestRefundAction` checks `getRemainingRefundableAmount()` **outside** the transaction and without locking the order. Concurrent requests can over-refund.
- `ProcessRefundAction`:
  - Checks status, then updates, without `lockForUpdate`, so concurrent approvals can issue two Stripe refunds.
  - Calls `gateway->refund()` **without a Stripe idempotency key** (`PaymentGatewayService::refund`).
  - On any exception, including a timeout after Stripe succeeded, marks the refund `Failed`. A retry (`RetryStuckRefundsCommand`, `RetryRefundExecutionAction`) can then refund twice.
- There is no reconciliation against Stripe refund objects (`charge.refunded` / `refund.updated` webhooks are not handled).

### 7.6 C6 — Checkout transaction design · **High · Risk · Code-confirmed** (`Application/UseCases/Order/CheckoutUseCase.php`, `Domain/Order/Actions/CreateOrderFromCart.php`)

- **External call inside the DB transaction.** The Stripe `paymentIntents->create` call (`CreatePaymentIntentAction`) runs inside the outer `DB::transaction`, while holding stock row locks.
  - If the commit fails afterwards, an orphan Stripe intent exists.
  - Stripe latency extends lock hold time.
  - The Stripe request carries **no idempotency key** (the local `idempotency_key` is not passed to Stripe).
- **Cart not locked.** Two concurrent submissions both pass `CartIsNotCompleted`. HTTP idempotency (`EnsureIdempotentAction`) only applies to **authenticated** users who send an `Idempotency-Key` header. It is check-then-act with no claim row: `lockForUpdate` on a missing row locks nothing, and it is not inside a transaction.
- **Prices taken from the cart snapshot** (`cart_items.price_cents_snapshot`) at add-to-cart time, without re-pricing or re-validation at checkout.
- **Currency conversion done per component** (subtotal, tax, shipping, each discount converted separately), so the converted parts may not sum to the converted total. The base currency comes from the ambient `Context::get('tenant')`.
- **Promotion usage recorded only when `$user !== null`.** Guests can reuse a limited code without bound.
- **Tax by region never applies.** `CreateOrderData::shippingAddress` is never populated by `CheckoutUseCase`.
- **Events dispatched before commit.** `OrderCreated` and `OrderStatusUpdated` (broadcast) fire inside the transaction with no `ShouldDispatchAfterCommit`, so listeners and broadcasts can observe rolled-back orders.

---

## 8. Security assessment

| # | Finding | Evidence | Sev / Kind / Evidence level |
|---|---|---|---|
| S1 *(revised in Addendum A.6: on production code this is **cross-vendor**)* | **Vendor dashboard has no role/permission check.** Routes use only `['auth','tenant.user']`. `ResolveTenantFromUser` just sets context from `users.tenant_id`. Storefront registration (`Web/AuthController::register` → `User::create`) gives customers the current store's `tenant_id` via `BelongsToTenant`'s `creating` hook. A **customer can open `/vendor/*`** and change order statuses, edit products and prices, adjust stock, export customer lists, answer reviews and toggle promotions. None of the 26 vendor controllers call `authorize`/Gate. Tests cover only "vendor" and "guest", never "customer". | `routes/web.php` vendor group; `app/Http/Controllers/Web/Vendor*.php`; `tests/Feature/Web/VendorDashboardTest.php` | **Critical / Defect / Code-confirmed** |
| S2 | **Tenant scope fails open.** `TenantScope::apply` adds no filter when `Context('tenant_id')` is null. Any route or job where context is not resolved is unscoped: users with `tenant_id NULL` on `/vendor/*`, guest API calls, queued jobs and console commands. Also, `/vendor/*` route-model binding (`{order}`, `{product}`, `{stock}`) may resolve **before** `tenant.user` sets context, depending on middleware priority. *Needs a runtime test to confirm cross-tenant IDOR.* | `app/Domain/Tenant/Scopes/TenantScope.php`; `bootstrap/app.php` | High / Risk / Code-confirmed (IDOR: assumption) |
| S3 | **Guest checkout of another user's cart.** `Api/V1/CheckoutController::checkout` checks ownership only when *both* the requester and the cart have a user. An anonymous request with any (sequential) `cart_id` checks out, completes and archives a logged-in user's cart, and reserves its stock. | `CheckoutController.php` (`if ($user !== null && $cart->user_id !== null …)`) | High / Defect / Code-confirmed |
| S4 | **Filament panel shared by platform and tenant staff**, with resources lacking policies. Filament grants access when no policy exists. No policy covers `SubscriptionPlan` (global, not tenant-scoped), `PaymentIntent`, `GiftCard`, `ShippingMethod`, `TaxZone`, `Bundle`, `ContentPage`, `Review`, `VariantAttributeType`, `PromotionExperiment`. `canAccessPanel` admits any tenant `admin`/`support`/`finance` user. | `app/Policies/` (10 policies), `User::canAccessPanel`, `app/Filament/Resources/*` | High / Risk / Code-confirmed (per-resource reachability: to verify) |
| S5 | **Exception messages exposed to clients.** The global Inertia error renderer passes `$e->getMessage()` for 500s. It also references an un-imported `HttpException` (resolves to global `\HttpException`, so `instanceof` is always false), and JSON clients receive an Inertia HTML page. | `bootstrap/app.php` `withExceptions` | Medium / Defect |
| S6 | **Webhook hardening.** An invalid signature throws an unhandled `SignatureVerificationException` and returns 500 (should be 400). There is no event-id de-duplication table. The whole Stripe event object is serialised into the queue payload. Amount and currency in the event are not checked against the local intent. | `Webhooks/StripeWebhookController.php`, `Jobs/HandleStripeEventJob.php` | Medium / Risk |
| S7 | Arbitrary status writes by vendors (S1 + §7.4) allow marking unpaid orders paid or delivered. That would trigger downloads (`GenerateDownloadsOnOrderPaid`) and loyalty or earnings if listeners key off status. | `VendorOrderController::updateStatus` | High / Defect |
| S8 | Rate limits exist on API groups (`throttle:20–60,1`). Login/register web routes have no explicit throttle *(assumption: no custom limiter found)*. | `routes/web.php`, `routes/api/v1.php` | Low / Risk |
| S9 | Staging shares the production DB and auto-deploys on CI success on `redesign`, with optional `migrate --force`. Staging code (including unreviewed WIP) can mutate production data. | `.github/workflows/deploy-staging.yml` header + "Finalize deploy" step | High / Risk / Verified (workflow text) |
| S10 | Demo credentials: all seeded users share `password`. Acceptable for demo only. The README documents it, and these seeders must never run against production. | `README.md`, `database/seeders/DatabaseSeeder.php` | Info |

**Not found (positive):** mass-assignment uses `$fillable`; Sanctum stateful API; CSRF-exempt only `webhooks/stripe` and `_boost/*`; download links are token-gated; `dompurify` is used for rich content.

---

## 9. Testing and quality assessment

| Item | Finding | Evidence |
|---|---|---|
| Volume | ~1,314 test cases / 135 files. Broad surface coverage. | `grep -c` |
| Execution | See §9.1 for this assessment's run. | Verified |
| DB engine | **SQLite in-memory** (`phpunit.xml`). Production is MySQL/MariaDB. Row locks, `unsigned`, JSON functions, collation and FK behaviour differ, and no locking or concurrency claim is testable. | `phpunit.xml:26-27` |
| Concurrency | `tests/Feature/Torture/*` are sequential loops (§7.3). No multi-process or multi-connection tests. | |
| Payments | Gateway mocked with Mockery in API tests (fine). **No webhook tests**, and no tests for duplicate or late webhooks, decline-then-succeed, or provider timeouts. | `tests/Feature/Api/V1/CheckoutApiTest.php` |
| Authorization | No "customer cannot use vendor dashboard" or "vendor A cannot mutate vendor B by id" tests on web vendor routes (`MultiTenancy/TenantIsolationTest.php` covers model scoping). | |
| Architecture | No `arch()` tests, no deptrac, no PHPStan/Larastan. | |
| Static analysis in CI | Pint (`--test`), Rector `--dry-run`, `composer audit`, `npm audit`, CodeQL (JS/TS only). **Missing:** PHPStan, `vue-tsc`, ESLint, Mago. | `.github/workflows/ci.yml`, `codeql.yml` |
| Frontend tests | None (no Vitest, no Playwright beyond 3 Pest "Browser" files). | `tests/Feature/Browser` |
| Test isolation | No global `tests/Pest.php`. Each file calls `uses(TestCase::class, RefreshDatabase::class)` itself. Inconsistent and easy to forget. | |

### 9.1 Test run performed for this assessment

See **Addendum A.1** for complete results. Summary: 1,311 tests: **1,293 passed, 17 failed, 1 skipped** (4,403 assertions), 2,121 s locally. All 17 failures are caused by the uncommitted WIP change to `routes/web.php`. CI on the committed `redesign` head (`774d131`) reports 1,291 passed, 1 skipped.

---

## 10. Performance risks

| # | Risk | Evidence | Sev |
|---|---|---|---|
| P1 | Stripe HTTP call made while holding stock row locks inside the checkout transaction | §7.6 | High |
| P2 | Unsorted row locking makes concurrent multi-item checkouts deadlock-prone | `CreateOrderFromCart::lockAndValidateStock` | Medium |
| P3 | 48 controllers build queries inline. Repeated status-count queries (`VendorOrderController::index` runs a separate `GROUP BY` on every request). No caching of catalogue facets. | | Low–Medium |
| P4 | Translations cached "forever until cleared" per locale and shipped on **every** Inertia response as a shared prop | `HandleInertiaRequests.php:64-97` | Low |
| P5 | `payment_intents.provider_reference` unindexed (webhook lookup) | D5 | Low (pilot scale) |
| P6 | Large monolithic pages (`Products/Show.vue` 1,370 lines) and no route-level code-splitting review | | Low |
| P7 | Typesense index requires manual `scout:import-all` after deploys, and failure is silent (README admits this) | `README.md` | Medium (operational) |

The pilot scale in `docs/product/goals.md` (<100 orders/day) means correctness, not throughput, is the binding constraint.

---

## 11. Technical debt summary

1. **Two product models in one codebase.** SaaS tenancy (subdomains, global scope, per-tenant categories, per-tenant currency) coexists with half-started marketplace semantics. On `redesign`, the session `shopping_tenant_id` (`ResolveTenantFromSubdomain::resolveShoppingTenant`, `Web/Concerns/RequiresShoppingTenant`) forces a cart to be single-store, contradicting ADR-0004.
2. **Two event mechanisms**, one of them dead (§7.2).
3. **Duplicate or dead code:**
   - `ReserveStock` / `ReserveStockAction`.
   - `CheckoutAction` (test-only).
   - Empty `HandlePaymentWebhookAction.php`.
   - `ConfirmPaymentIntentJob` (never dispatched in production; *to verify*).
4. **Feature sprawl.** About 10 "Experimental" features (subscriptions, gift cards, A/B promotion experiments, chat, Q&A, waitlist, abandoned cart, fraud dashboard, downloads, AI recs) each add schema, Filament resources and test load.
5. **Unpinned dependencies** (`stripe/stripe-php`, `laravel/reverb`, `nativephp/electron` set to `*`), and `faker` in production `require`.
6. **Stale documentation** that over-claims (README, INVARIANTS, PRODUCTION_READINESS_REVIEW).
7. **Uncommitted WIP** (44 files) on a branch that auto-deploys to a production-connected staging site.

---

## 12. Reusable vs replaceable matrix

| Component | Disposition | Rationale |
|---|---|---|
| Integer-cents money convention; `currency` + `exchange_rate` snapshot on orders | **Reuse (concept)** | Correct; formalise as a `Money` value object |
| `OrderStatus` / `PaymentStatus` / `RefundStatus` enums + `canTransitionTo` | **Reuse as reference** | Good starting state machines; fix gaps (§7.4) and enforce in one place |
| Specification pattern (`app/Shared/Specifications`) | **Reuse selectively** | Useful for composable eligibility rules (promotions, refundability). Avoid using it for every guard. |
| Typed IDs (`AbstractId`, `OrderId`…) | **Optional** | Adds ceremony. Decide in the ADR on conventions. |
| Order-line snapshotting (`price_cents_snapshot`, `tax_cents_snapshot`) | **Reuse (concept)** | Correct for financial history |
| Idempotency-key model | **Re-implement** | Concept right, implementation racy (§7.6) |
| `CheckoutUseCase` / `CreateOrderFromCart` | **Replace** | Must become OrderGroup + per-vendor orders, with an external call outside the transaction and sorted locks |
| Inventory actions | **Replace** | Semantics inconsistent (§7.3). Redesign as on-hand + reservations ledger. |
| Stripe gateway adapter & webhook job | **Replace** | Wrong confirm flow, no event de-dup, no idempotency keys |
| Refund actions | **Replace** | Races, unsafe retries (§7.5) |
| `DomainEventRecorder` / `DispatchDomainEventsJob` | **Replace** with a real transactional outbox (if ADR approves) | §7.2 |
| `BelongsToTenant` global scope | **Retire** | Per ADR-0003: explicit vendor scoping + policies + tests |
| Subdomain tenancy, onboarding `/start` trial, `tenant-switcher` | **Retire** | Contradicts the single-storefront model |
| Filament control plane | **Keep the technology; rebuild resources** | Filament 5 suits an operator back office. Rebuild with policies, separate it from vendor UI, and remove tenant-staff access. |
| Vendor dashboard (Vue pages) | **Reference only** | Good feature inventory. Rebuild on the new design system with authorization. |
| Storefront Vue pages | **Reference only** | Rebuild with logical-property RTL and typed contracts |
| `lang/*/ui.php` strings | **Reuse as seed** | Restructure per namespace |
| Metrics / alerting / correlation-id / audit log (`app/Shared`) | **Evaluate in Phase 8** | Possibly useful. Not on the critical path. |
| Experimental features (§11.4) | **Retire from v2 scope** unless the owner promotes them | Reduces surface area |
| CI workflows | **Rewrite** | Add PHPStan, vue-tsc, ESLint, MySQL service, arch tests. Remove the prod-DB staging. |
| Seeders/factories | **Rewrite** | New schema; curated demo dataset (brief §14) |

---

## 13. Target architecture proposal (initial — for ADRs in Phase 1)

### 13.1 Shape: a modular monolith, one Laravel app

```
app/
  Modules/ (or src/)            one folder per bounded context, each with:
    <Context>/
      Domain/        entities/aggregates, value objects, domain events, policies (pure PHP where practical)
      Application/   commands/queries (use cases), DTOs, ports (interfaces)
      Infrastructure/Eloquent models, repositories/queries, gateways (Stripe), listeners
      Http/          controllers, form requests, resources (Inertia props), routes
      Contracts/     the ONLY namespace other modules may import (interfaces + DTOs + events)
  Platform/          cross-cutting: Money, Clock, Ids, Outbox, Idempotency, Audit
```

Whether Eloquent models live in `Domain` (pragmatic) or behind repositories (purist) is itself an ADR. **Recommendation: pragmatic.**

- Eloquent models live inside the module.
- Business rules go in aggregate methods and domain services.
- Other modules access them only through `Contracts`.
- Enforcement uses Pest `arch()` tests plus deptrac.

### 13.2 Proposed bounded contexts (fewer than the 29 folders today)

| Context | Owns | Notes |
|---|---|---|
| **Identity & Access** | users, roles/permissions, vendor memberships, sessions | Shopper is marketplace-wide. Vendor staff hold a membership row (`vendor_id`, role). Operators are platform roles. No `tenant_id` on users. |
| **Vendors** | vendor profile, application/approval lifecycle, payout account reference, commission overrides | Rename `tenant` → `vendor` (fresh DB makes this free) |
| **Catalogue** | platform taxonomy (categories, attributes), products, variants, media, moderation state, pricing (list/sale price per variant) | Pricing stays inside Catalogue until promotions need more |
| **Inventory** | stock per variant (on-hand), reservations (with TTL), movements ledger | Single-writer of stock; invariant `reserved ≤ on_hand` enforced in DB and code |
| **Ordering (Cart + Checkout + Orders)** | cart, checkout session, **OrderGroup**, **VendorOrder**, lines, fulfilment state machine | Checkout orchestrates Inventory + Payments + Promotions via contracts |
| **Payments & Finance** | payment attempts, provider events (de-duplicated), refunds, **ledger** (customer charge, commission, vendor payable, refund liability), payouts (later) | Its own context because money has its own lifecycle and audit needs |
| **Promotions** | campaigns, codes, eligibility, allocation | Platform-run first |
| **Engagement** | reviews, wishlist, (loyalty if promoted) | |
| **Search** | read model / Typesense projection | Fed by events; never a source of truth |
| **Notifications** | channels, templates, preferences | Event subscriber only |
| **Platform Ops** | audit log, configuration, feature flags, health | |

**Dependency direction (proposed):**

```
Http → Application → Domain;  Infrastructure → Domain
Ordering → {Catalogue, Inventory, Payments, Promotions}.Contracts
Payments → Ordering.Contracts (events only)
Search, Notifications → *.Contracts events only
Forbidden: any module importing another module's Domain/Infrastructure;
           Domain importing Illuminate\Http, Filament, Inertia;
           cross-module Eloquent relationships / joins outside read-model queries.
```

### 13.3 Key transactional design (to be decided by ADRs, not implemented yet)

- **Checkout runs as a short saga:**
  1. Validate and re-price.
  2. One DB transaction creates the `OrderGroup`, `VendorOrders` and reservations (TTL), with locks taken in sorted order. It also writes outbox rows.
  3. After commit, create the provider payment (idempotency key = checkout id).
  4. Webhook drives `paid`, which consumes the reservations.
  5. A scheduled sweeper releases expired reservations and cancels unpaid groups.
- **Webhooks:**
  - Verify the signature, return 400 on failure.
  - Insert into `provider_events(provider, event_id UNIQUE)` and return 200.
  - Process asynchronously with row locks.
  - Re-fetch the provider object for authoritative state.
- **Money:** a double-entry ledger for commission, vendor payable and refund liability, even before real payouts exist. It makes reconciliation testable without Stripe Connect.
- **Outbox:** written in the same transaction and drained by a scheduled worker using `SKIP LOCKED`, with attempts, backoff and dead-letter.
- **Concurrency tests:** run on MySQL in CI using real parallel processes or connections (e.g. `pcntl_fork` or `amphp/parallel`, already a dependency).

### 13.4 Stack recommendation (each item an ADR)

| Decision | Recommendation | Why |
|---|---|---|
| Laravel version | **Laravel 13 / PHP 8.4+** for the fresh start | Fresh app, no upgrade cost. Local PHP is 8.5. Pick the newest stable LTS-equivalent the host supports. |
| Frontend | **Vue 3 + Inertia (v2 or v3) + TypeScript (strict)**, per the brief | The brief says Vue, but the separate `Nexo` repo chose React. Owner must confirm (Q2). |
| Admin | **Keep Filament** for the operator panel only. Vendor dashboard in Vue/Inertia. | Filament excels at operator back-office CRUD, and vendors deserve a product-grade UI |
| DB | **MySQL 8** (dev via Docker/Laragon; CI service container). Stop testing on SQLite for anything transactional. | Locking semantics |
| Queue/cache | Redis | Already present |
| Search | Start with **MySQL full-text + good indexes**. Add Typesense behind a `Search` port when demo data warrants it. | Reduces moving parts. Keeps search an ADR, not a default. |
| Realtime | Defer Reverb until a feature needs it (order status push) | Fewer services for reviewers to run |
| Payments | Stripe in **test mode** behind a `PaymentProvider` port. Connect/payout decision deferred to the finance ADR. | See Q1/Q3 |
| Static analysis | PHPStan/Larastan (level 8 target), Pint, Rector, `vue-tsc`, ESLint, Pest `arch()`, deptrac | Brief §13 |
| Contracts | Typed Inertia props generated from PHP DTOs (e.g. `spatie/laravel-data` + `typescript-transformer`) | Avoids duplicated rules and hand-written types. Needs an ADR. |

---

## 14. ADR backlog (proposed, none written yet)

| # | Title | Depends on |
|---|---|---|
| 0001 | ADR process, template (brief §18 fields), statuses | — |
| 0002 | Product context & market (portfolio showcase vs Sudan pilot), currencies, locales | Q1 |
| 0003 | Fresh repository vs fresh branch; Laravel/PHP versions; Vue vs React | Q2 |
| 0004 | Modular-monolith structure, module contracts and enforced dependency rules (arch tests + deptrac) | 0003 |
| 0005 | Identity model: shopper / vendor membership / operator; RBAC library choice | 0004 |
| 0006 | Vendor ownership & data isolation (explicit scoping, policies, isolation test suite as merge gate) — supersedes old ADR-0003 | 0005 |
| 0007 | Catalogue: platform taxonomy, attributes, variants, media, moderation | |
| 0008 | Inventory model: on-hand + reservations with TTL + movement ledger; DB constraints; lock ordering | |
| 0009 | Order aggregate: OrderGroup + VendorOrder; state machines; who may transition what | |
| 0010 | Checkout saga, transaction boundaries, idempotency (HTTP + provider) | 0008, 0009 |
| 0011 | Payment provider integration: intent lifecycle, webhook ingestion & de-dup, reconciliation | 0010 |
| 0012 | Financial model: ledger, commission freezing, vendor payables, refunds/chargebacks, payouts deferral | 0011 |
| 0013 | Domain events & transactional outbox | 0004 |
| 0014 | Backend↔frontend contract strategy (DTO → TS generation, money/date/enum serialisation, error shape) | |
| 0015 | Search strategy (DB first vs Typesense) | |
| 0016 | i18n & RTL strategy (logical properties, locale routing, currency/number/date formatting) | 0002 |
| 0017 | Testing strategy: MySQL in CI, concurrency harness, failure-simulation fakes | |
| 0018 | Environments & data isolation: demo env, seeding, **no shared prod DB** | |
| 0019 | Admin surface: Filament scope and separation from vendor UI | |
| 0020 | Feature retirement list (experimental features out of v2 scope) | Q5 |

---

## 15. Risk register (prioritised)

| ID | Risk | Likelihood | Impact | Severity | Mitigation / phase |
|---|---|---|---|---|---|
| R1 | Carrying the broken payment, inventory and refund cores into v2 by "reusing" them | High if reused | Critical | **Critical** | Re-implement behind ADR-0008..0012, test-first on MySQL (P3–P5) |
| R2 | Market/payment-provider mismatch (Sudan + Stripe) invalidates the money design | High | Critical | **Critical** | Owner decision Q1 before Phase 1 money ADRs |
| R3 | Vendor isolation regressions after removing the global scope | Medium | Critical | **Critical** | Policies + isolation suite as merge gate + arch test (ADR-0006) |
| R4 | Concurrency bugs undetected because tests run on SQLite | High | High | **High** | MySQL in CI + parallel-process harness (ADR-0017) |
| R5 | New work touching the production DB (shared-DB staging, copied `.env`) | Medium | Critical | **High** | Separate repo/env; never reuse prod credentials; disable staging-on-prod workflow for v2 (ADR-0018) |
| R6 | Scope creep from ~40 legacy features | High | Medium | **High** | Explicit v2 scope (Q5, ADR-0020) |
| R7 | Uncommitted `redesign` WIP lost or auto-deployed | Medium | Medium | **Medium** | Owner decides: commit to a branch, stash, or discard (Q8) |
| R8 | Financial model under-specified (commission, refunds, chargebacks) | Medium | High | **High** | ADR-0012 before Phase 5 |
| R9 | RTL treated as mirroring | Medium | Medium | **Medium** | Logical properties from the design system up (ADR-0016) |
| R10 | Portfolio credibility: README claims outrun behaviour | High (today) | High | **High** | Evidence-based docs; CI badges; reproducible demo (P10) |
| R11 | Unpinned dependencies / ignored audit advisory | Medium | Medium | **Medium** | Pin; review GHSA-vvj3-c3rp-c85p (Q9) |
| R12 | Solo-engineer bandwidth vs a 10-phase plan | High | Medium | **Medium** | Keep v2 scope tight; prefer depth (money/inventory) over breadth |

---

## 16. Recommended phase sequence

The brief's sequence is sound. Recommended adjustments:

1. **Before Phase 1:** owner answers Q1–Q5 (they change the PRD and money ADRs).
2. **Phase 1:** write ADR-0001..0006 and 0008..0012 *before* any design work. Financial and inventory invariants drive schema, which drives UI states (pending, partially refunded, etc.).
3. **Phase 2 (design system)** can run in parallel with late Phase 1, since it does not depend on the money ADRs.
4. **Phase 3:** stand up a **new repository or orphan branch** (Q2) with MySQL-in-CI, arch tests and the test harness *first*, so every later phase lands against real gates.
5. **Phases 4–5:** implement Inventory and Checkout/Payments **test-first**, including the §10/§11 failure scenarios from the brief, before building any storefront UI on them.
6. **Phase 7 (frontend):** port features from the old Vue pages using the new design system and typed props.
7. Throughout: no environment of v2 ever receives production credentials (R5).

---

## 17. Questions requiring owner decisions

| # | Question | Why it matters | My recommendation |
|---|---|---|---|
| **Q1** | **What is v2's product context?** (a) Portfolio showcase of a generic marketplace (brief), (b) the real Sudan-market aljebal-albeedos pilot (ratified docs), or (c) both. | Determines payment provider, currency (SDG/MYR/USD), locales' priority, demo data, and whether Stripe Connect is viable. | (a) for v2, with a provider-agnostic payment port so (b) remains possible later |
| **Q2** | **Where does v2 live, and in what frontend?** (a) New repo, (b) orphan branch in `nexo-ecommerce`, or (c) continue `C:\laragon\www\Nexo` (Laravel 13 + **React**, 67 commits). And Vue (brief) vs React (Nexo repo)? | Repo history and recruiters' first impression; Vue/React choice is fundamental | New repo (or `Nexo` repo reset) on Laravel 13 + **Vue 3/Inertia + TS**, as your brief states. Keep `nexo-ecommerce` read-only as the reference. |
| **Q3** | Payments: test-mode Stripe with an **internal ledger** and simulated payouts (no Connect), or real Stripe Connect (separate charges & transfers)? | Connect adds onboarding/KYC flows and country constraints | Internal ledger + test-mode Stripe; Connect later via ADR |
| **Q4** | Is the old live site (`store.aljebal-albeedos.com`) staying up on the old code? If yes, who maintains it, and should the C1/C3/S1 defects be hot-fixed there? | Real customers may be charged without orders being marked paid; customers can access vendor tools | If it takes real payments, hot-fix or take payments offline; that is outside the v2 phases and needs your explicit go-ahead |
| **Q5** | v2 scope: which "Extended/Experimental" features survive? (loyalty, referrals, bundles, gift cards, subscriptions, chat, Q&A, waitlist, flash sales, digital downloads) | Scope and schema | Keep: promotions (platform-run), reviews, wishlist. Defer the rest. |
| Q6 | Single currency for v2 (and which), or multi-currency display with single settlement currency? | Money model | Single settlement currency (e.g. MYR or USD); display conversion later |
| Q7 | Primary locale and RTL priority: is Arabic primary, or English with Arabic/Malay as full peers? | Design system defaults | English primary for portfolio; AR/MS full peers with real RTL |
| Q8 | What to do with the 44 uncommitted files on `redesign` (and the auto-deploy-to-prod-DB staging workflow)? | Risk of loss or accidental prod mutation | Commit to a WIP branch you don't push, or stash; disable `deploy-staging.yml` for that branch |
| Q9 | Why is `GHSA-vvj3-c3rp-c85p` in `composer.json` audit ignore? | Supply-chain hygiene | Re-evaluate in the new repo |
| Q10 | Hosting target for the demo (VPS as today, or a PaaS)? | Phase 3 environment and Phase 10 deployment docs | Decide by Phase 3 |

---

## 18. Phase 0 acceptance checklist (for the owner)

- [ ] Major business workflows understood (browse → cart → checkout → pay → fulfil → refund) — §3, §7
- [ ] Existing architecture documented — §3, §4
- [ ] Critical invariants and their violations identified — §7
- [ ] Weaknesses evidenced with file/class references — §5, §7, §8
- [ ] Target architecture proposed with justification — §13
- [ ] ADR backlog and risk register — §14, §15
- [ ] Open questions listed — §17

**Method note:** all inspection was read-only. Commands executed:

- `git` log, status and diff.
- `grep`/`find`.
- `vue-tsc --noEmit`.
- The Pest suite, with every external credential (Stripe, Google, Typesense, Mailtrap, FX API) overridden by dummy values in the process environment.

The only file written to the repository is this report.

---

# Addendum A — Owner review clarifications (2026-11-01, still Phase 0)

This addendum answers the owner's eight review points. Everything was gathered read-only:

- File reads, `git` read commands, `git ls-remote`, and `gh run list/view` (GitHub Actions metadata and logs).
- Local test runs, with every external credential replaced by a dummy value.

No deployment, migration, stash, reset, clean, checkout or commit was executed in either repository.

**Repository side effects, disclosed in full:**

1. This report file (new, untracked).
2. My first test attempt set `DB_DATABASE=__discovery_never_used` in the process environment, intending to guard the real DB. Because environment variables override `phpunit.xml`'s `<env>` values, that run used a SQLite **file** of that name in the repo root instead of `:memory:`. I stopped that run, deleted the file, and deleted a 0-byte remnant left by the stopped process. No MySQL database was touched. The `.env` points at MySQL, which was never reached.

## A.1 Complete test-suite results (review point 1)

### Old repository (`modular-ecommerce-system`, working tree = `redesign@774d131` + 44 uncommitted files)

| Item | Value |
|---|---|
| Command | `php vendor/bin/pest --compact` (Pest 4 / PHPUnit 12, PHP 8.5.8 NTS, Windows 11) |
| Environment overrides | `DB_CONNECTION=sqlite DB_DATABASE=:memory:`. Dummy `STRIPE_SECRET/KEY/WEBHOOK_SECRET`, `GOOGLE_CLIENT_SECRET`, `TYPESENSE_*`, `MAILTRAP_API_TOKEN`, `REVERB_APP_SECRET`. `MAIL_MAILER=array`. `CURRENCY_API_URL=http://127.0.0.1:9` (unreachable on purpose). `phpunit.xml` additionally forces `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SCOUT_DRIVER=collection`, `BROADCAST_CONNECTION=null`. |
| **Total** | **1,311 tests, 4,403 assertions** |
| **Passed** | **1,293** |
| **Failed** | **17** |
| **Skipped** | **1**: `tests/Feature/Domains/Order/CreateOrderConcurrencyTest.php:85`, reason "Requires amphp/parallel package for concurrent testing". Notably, this is the only test that would have exercised real concurrency, and it never runs. |
| Risky / incomplete / todo | None reported |
| Warnings / deprecations | None printed by the runner (compact mode prints PHP deprecations if any are triggered; none appeared) |
| **Wall-clock duration** | **2,121.27 s (≈35 min)**. Each test re-runs 109 migrations on a fresh in-memory DB under Windows. CI on Linux takes 46.8 s for the same suite. |
| Raw log | `C:\Users\MCR\AppData\Local\Temp\claude\C--laragon-www\ef9ce4e1-abb5-4e83-856a-1347f2ba36a5\scratchpad\pest-clean.txt` |

**The 17 failures:**

| Test file | Failing tests | Error |
|---|---|---|
| `tests/Feature/Browser/AuthTest.php` | 15: Login (7), Registration (7), Logout (1) | `Expected response status code [>=200, <300] but received 404` (and the 3xx variants) |
| `tests/Feature/AdminImpersonationTest.php` | 2: "shares impersonation state as inactive…", "shares active impersonation state…" | `Not a valid Inertia response.` (the response was a 404, not an Inertia page) |

**Root cause (verified):**

- The uncommitted change to `routes/web.php` adds `tenant.subdomain` (`ResolveTenantFromSubdomain`) to the whole `/{locale}` storefront group (diff: `->middleware('locale')` → `->middleware(['locale', 'tenant.subdomain'])`).
- These two test files make requests without creating a tenant whose domain matches the test host, so the middleware throws `NotFoundHttpException('Tenant not found.')`.
- **Confirmation run:** re-running only these two files with `APP_URL=http://localhost` (CI's value) reproduced the same failures, which rules out my local `APP_URL=http://127.0.0.1:8000` as the cause.
- The tests that pass after the WIP change rely on the WIP's modified `tests/Traits/WithTenant.php`, which now sets the tenant's `domain` to the test host. These two files do not use that trait.

**Baseline for comparison (read-only from GitHub Actions):** CI run `32982452177` on committed `redesign@774d131` (2026-08-26, Ubuntu, PHP 8.4): **1,291 passed, 1 skipped, 0 failed, 4,354 assertions, 46.77 s.** The 20-test difference comes from tests added or changed in the WIP.

**What these numbers do and do not show:**

- The suite passing on committed code does **not** contradict §7–§8.
- No test covers the webhook path, a customer reaching `/vendor/*`, guest cart takeover, or real concurrency.
- The torture tests' assertions tolerate the inventory double-subtraction.

### Frontend type check (old repository)

`npx vue-tsc --noEmit -p tsconfig.json` reports **22 errors**: TS2339 ×14, TS18046 ×4, TS2322 ×3, TS2353 ×1. By file: `Products/Show.vue` 10, `Vendor/Dashboard.vue` 4, `Orders/Show.vue` 3, `Vendor/Orders.vue` 2, `Refunds/Status.vue` 1, `Error.vue` 1, `UI/DataTable.vue` 1. The process exit status was reported as 0 through the shell pipeline, but the error count above is what the log contains. CI does not run this check.

### Separate `Nexo` repository

| Suite | Command | Result | Duration |
|---|---|---|---|
| Pest (backend) | `php vendor/bin/pest`, with `APP_CONFIG_CACHE=<nonexistent scratch path> DB_CONNECTION=sqlite DB_DATABASE=:memory:` | **109 tests: 109 passed, 0 failed, 0 skipped**, 1,038 assertions (Laravel Pao JSON summary) | 52.1 s (56 s wall) |
| Vitest (frontend) | `npx vitest run` | **33 files / 78 tests: 78 passed, 0 failed** | 88.6 s |

**Finding (environment hazard, Medium):**

- `C:\laragon\www\Nexo\bootstrap\cache\config.php` (git-ignored, dated 2026-09-10) is a cached config. When it exists, Laravel ignores `phpunit.xml`'s `DB_CONNECTION=sqlite`, so the tests ran against the `.env` MySQL database **`nile`**.
- My first two attempts therefore tried `127.0.0.1:3306/nile`. MySQL was not running: each test waited ~16 s for a refused connection, and the run hit my 900 s timeout with no output (Pao prints only at the end).
- **Had MySQL been running, `RefreshDatabase` would have dropped and re-migrated that local `nile` database.**
- To avoid modifying the repository I did not run `config:clear`. I bypassed the cache with the `APP_CONFIG_CACHE` environment variable.
- Fix for v2: tests should refuse to run against a non-test database, for example a guard in `TestCase` that asserts the connection is the test connection.

## A.2 Stripe payment failure: confirmed by source vs needs test-mode verification (review point 2)

### A.2.1 Confirmed by source inspection

| # | Fact | Evidence |
|---|---|---|
| F1 | No production code instantiates or dispatches `App\Domain\Payment\Events\PaymentSucceeded`. Only the class, the `MarkOrderPaid` handler type-hint and two tests reference it. | `grep -rn PaymentSucceeded app tests` |
| F2 | `MarkOrderPaid` is the only listener that sets `Paid` after payment. The other writer, `RetryOrderFinalizationAction`, is reachable only from the manual command `payments:reconcile-orphaned`, which `routes/console.php` does **not** schedule. | `Domain/Order/Listeners/MarkOrderPaid.php`, `Console/Commands/ReconcileOrphanedPaymentsCommand.php` |
| F3 | The browser confirms the intent client-side with `stripe.confirmPayment(...)`. | `resources/js/Composables/usePayments.ts:91` |
| F4 | On `payment_intent.succeeded`, `HandleStripeEventJob::handleSuccess()` → `ConfirmPaymentIntentAction::execute()` → `PaymentGatewayService::confirmIntent()` → `$stripe->paymentIntents->confirm($provider_reference)`. | `app/Jobs/HandleStripeEventJob.php`, `app/Infrastructure/Payment/Stripe/PaymentGatewayService.php` |
| F5 | That Stripe call runs inside `DB::transaction`. On any `Throwable` the action writes `status=Failed` and **rethrows**, so the write is rolled back and the job fails. | `Domain/Payment/Actions/ConfirmPaymentIntentAction.php` |
| F6 | On its success branch the action only updates `payment_intents.status`. It dispatches no event and never touches the order. | same |
| F7 | `handleFailure()` sets intent and order to `Failed`. `PaymentStatus::Failed` is terminal, so a later `payment_intent.succeeded` for the same intent hits the `isTerminal()` early-return and is ignored. | `HandleStripeEventJob.php`, `Domain/Payment/Enums/PaymentStatus.php` |
| F8 | `POST /api/v1/checkout/confirm-payment` (auth only) performs the same server-side confirm. Guests have no equivalent. | `Api/V1/CheckoutController::confirmPayment` |
| F9 | `SignatureVerificationException`/`UnexpectedValueException` are not caught, so an invalid signature returns HTTP 500 instead of 400. | `Webhooks/StripeWebhookController.php` |
| F10 | No test covers `HandleStripeEventJob` or `POST /webhooks/stripe`. | `grep` over `tests/` |

**Conclusion that needs no Stripe call:** because of F1, F2 and F6, the webhook never moves an order to `Paid`, **whatever Stripe answers**. Stripe's behaviour only decides *how* it fails: an exception and failed job, or an intent silently marked `Succeeded` while the order stays `Pending`.

### A.2.2 Requires Stripe test-mode verification (not executed; needs your authorisation)

| # | Hypothesis | Why it matters | Test-mode procedure |
|---|---|---|---|
| V1 | Confirming an already-`succeeded` intent returns an error (Stripe documents `payment_intent_unexpected_state`) | Exception path (job fails, intent stays `Processing`) vs silent path (intent `Succeeded`, order `Pending`) | Fresh local DB, test keys, `stripe listen --forward-to http://localhost:8000/webhooks/stripe`, pay with `4242 4242 4242 4242`, inspect `payment_intents`, `orders`, `failed_jobs` |
| V2 | Decline then retry on the same intent ends with a charged customer and a `Failed` order (F7) | Real-money impact if live keys are in use | Card `4000 0000 0000 0002`, then `4242…` in the same Payment Element |
| V3 | Server `QUEUE_CONNECTION`: `sync` makes the webhook itself return 500 so Stripe retries for 3 days; `database`/`redis` records failures in `failed_jobs` | Where the evidence is, and whether Stripe has disabled the endpoint | Owner reads server config (read-only) |
| V4 | Intent creation without `automatic_payment_methods`/`payment_method_types` works under the API version pinned by `stripe/stripe-php v17.6.0` | Whether the Payment Element even renders | Same harness as V1 |
| V5 | The production webhook endpoint exists, subscribes to the right events, and the secret matches | If missing, orders stay `pending` for a different reason | Stripe Dashboard → Developers → Webhooks |

Recommendation: run V1, V2 and V4 only in local test mode against a throw-away database. Do it now only if you need the answer for the live-site decision (A.6); otherwise it belongs in Phase 5's failure-simulation suite.

## A.3 Preservation plan for uncommitted work (review point 3): proposal only, nothing executed

### A.3.1 Inventory (verified)

**`modular-ecommerce-system`, branch `redesign` @ `774d131`** (local and `origin/redesign` identical, 0/0 ahead/behind):

- **33 modified tracked files** (+801/−73 lines):
  - `README.md`
  - `app/Domain/Tenant/Models/Tenant.php`
  - 3 Filament `VariantAttributeTypes` files
  - `Web/{Auth,Cart,Checkout,Home,Store}Controller.php`
  - `Middleware/ResolveTenantFromSubdomain.php`, `config/tenancy.php`
  - `database/factories/TenantFactory.php`, `database/seeders/DatabaseSeeder.php`
  - `lang/{ar,en,ms}/ui.php`
  - `Components/Layout/{BottomNav,MobileNav}.vue`, `Layouts/{Authenticated,Guest}Layout.vue`, `Pages/{Home,Stores/Show}.vue`
  - `routes/web.php`
  - 9 files under `tests/Feature/**` and `tests/Traits/WithTenant.php`
- **11 untracked paths:**
  - `app/Domain/Tenant/Models/StoreFollow.php`
  - `app/Http/Controllers/Web/Concerns/RequiresShoppingTenant.php`
  - Migrations `2026_08_27_142036_add_marketplace_fields_to_tenants_table.php` and `2026_08_27_142044_create_store_follows_table.php`
  - `database/seeders/VariantAttributeTypeSeeder.php`
  - `docs/REDESIGN_BRIEF.md`
  - `docs/architecture/` (ADR-0001..0004, `marketplace-migration.md`)
  - `docs/product/` (5 docs)
  - `resources/js/Components/Stores/StoreCard.vue`, `resources/js/Pages/Stores/Index.vue`
  - `tests/Feature/Filament/VariantAttributeTypeResourceTest.php`
  - Plus this report under `docs/discovery/`.
- **Git-ignored but not reproducible from Git:** `.env` (secrets), `CLAUDE.md`, `Check.md`, `boost.json`, `.mcp.json`, `.idea/`.
- **Stashes:**
  - `stash@{0}` "WIP on main: d0ba78c bulk product import".
  - `stash@{1}` "WIP on chore/ci-cd-wiki: 489e35e".
- **Agent worktree** `.claude/worktrees/agent-a8ba81d6`, branch `worktree-agent-a8ba81d6` @ `5975250` (2026-03-22). It has its own uncommitted changes: ≥10 modified files in the review-submission area.

**`C:\laragon\www\Nexo`:**

- **Branch `feat/vendor-frontend` holds 61 commits with no upstream.** `git ls-remote` lists only `refs/heads/main` on `Sentients-AI/Nexo`. These commits exist on this disk only. **This is the largest loss risk found.**
- 3 modified files (`app/Http/Middleware/SetLocale.php`, `app/Providers/AppServiceProvider.php`, `resources/css/app.css`) and `stash@{0}`.
- Untracked: `Form dependencies for artboards/` (3.1 MB design handoff `design_handoff_nile_lagoon`, cited as the design source of truth), `.github/hooks/`, `.github/workflows/hello.yaml`, and a vim swap file.

### A.3.2 Plan (each step needs your approval; steps 1–3 do not modify either repository)

1. **Out-of-repo snapshot** to e.g. `C:\laragon\backups\nexo-2026-11-01\`:
   - `git diff --binary` → `redesign-tracked.patch`.
   - Path-preserving copies of all untracked and ignored files above. `.env` is kept local only and **never committed or uploaded**.
   - `git stash show -p --include-untracked stash@{N}` → one patch per stash.
   - `git -C .claude/worktrees/agent-a8ba81d6 diff --binary` → `agent-worktree.patch`.
   - `git bundle create … --all` for both repositories. This captures every branch, including the 61 unpushed commits and the worktree branch.
   - The same diff and untracked copy for the `Nexo` repo.
2. **Verify** without touching the repositories:
   - `git bundle verify`.
   - Clone each bundle into a scratch folder.
   - Check out `774d131` there and run `git apply --check` on the patch.
   - Record SHA-256 checksums of every artefact.
3. **Off-machine copy:** you copy the folder somewhere you control. I will not upload it.
4. *(Optional, separate approval, mutating)*
   - Commit the old-repo WIP to a **new local branch** `wip/marketplace-pivot-2026-08`.
   - Push `feat/vendor-frontend` to `Sentients-AI/Nexo`.
   - **Never push to `redesign`.** A green CI on `redesign` auto-deploys to the production-DB staging site (A.5). Any other branch name only runs CI.
5. *(Later)* Disposition:
   - Keep `docs/product/*` and ADR-0002..0004 as v2 inputs.
   - Treat the code WIP (session "shopping tenant", StoreFollow, `tenant.subdomain` on the storefront) as reference only. It contradicts ADR-0004 and breaks 17 tests.

## A.4 The `C:\laragon\www\Nexo` repository (review point 4)

| Dimension | `Nexo` (`Sentients-AI/Nexo`) | Old repo (`AdilAzhari/nexo-ecommerce`) |
|---|---|---|
| History | 67 commits, 2026-08-31 → 09-15. `main` (6 commits; `origin/main` 2 ahead with merged PR #4 "vendor domain documentation"). `chore/phase-0-foundation` (pushed). `feat/vendor-frontend`: 61 commits, **local only**. Plan-driven conventional commits (D1–D7, B1–B2). | 208 commits on `redesign`; SaaS history plus many deploy fixes |
| Author identity | 60 × `adelazhary1997@…`, 3 × `adelazhry1997@…`, 4 × `adilazhariosman@…`: **mixed** | — |
| Backend stack (verified, `composer.json`) | PHP `^8.5`, **Laravel `^13.17`**, `inertiajs/inertia-laravel ^3.3`, Ziggy. Dev: **Larastan 3 / PHPStan 2**, Pint, **Pest 5**, Pao, Pail | PHP ^8.2, Laravel 12.68, Inertia 2.0.19, Filament 5.7.6, Cashier 16.1, stripe-php 17.6, Reverb, Scout/Typesense, Pest 4, Rector, Mago |
| Frontend stack (verified, `package.json`) | **React 19.2, `@inertiajs/react` 3.7, TypeScript 6, Vite 8, Tailwind 4, Vitest 5 + Testing Library, ESLint 9, Prettier** | Vue 3.5, `@inertiajs/vue3` 2.3, TS 5.9, Vite 7, Tailwind 4; no frontend tests, no linter |
| Backend maturity | **Skeleton.** 42 PHP files: one model (`User`), 3 framework migrations (users/cache/jobs), 14 vendor + 11 auth controllers that render **static fixture arrays** (`app/Support/Fixtures/*`). No domain, persistence, auth, payments or inventory. | Extensive but defective (§7–§8) |
| Frontend maturity | **High for the vendor workspace:**<br>• 134 files, ~12.2k lines TS/TSX.<br>• UI kit of ~40 components (`components/ui`: DataTable, Sheet, MoneyInput, Stepper, Timeline, StatusBadge, TypedConfirm, charts, …) with co-located tests.<br>• `lib/` money, format, i18n, theme, status and countdown utilities, each with tests.<br>• "Lagoon" design tokens, Bidi/Money primitives, EN+AR locale files.<br>• Every vendor screen D1–D7 and auth B1–B2.<br>• Spec `docs/frontend/vendor-frontend-spec.md` (440 lines, "Shipped 2026-09-14"). | Functional but monolithic pages; 22 type errors; physical-direction CSS |
| Tests | 43 PHP test files: page renders, fixture shapes, locale routing, `arch()` presets (no debug helpers, php and security presets, `env()` only in config). ~20 Vitest files. Results below. | 1,311 Pest tests (A.1) |
| CI (`ci.yml`) | composer validate + audit, Pint, **PHPStan**, `php artisan test`. **Does not** run `npm ci`, `tsc`, ESLint or Vitest. | Pint, Rector dry-run, Pest/SQLite, audits, CodeQL-JS |
| Docs | Product docs (incl. `requirements/{shopper,vendor,platform}`), ADR-0001..0004 (same decisions as the old repo's untracked ADRs), engineering handbook, 4 implementation plans | SaaS-era docs, partly over-claiming |

**Nexo test results** (A.1):

- Pest 109/109 passed in 52 s. Vitest 78/78 passed in 89 s.
- Those tests cover page rendering, fixture shapes, locale routing, arch presets and UI components only. They say nothing about business logic, because there is none yet.

**Suitability as the Nexo 2.0 foundation:**

- **Backend:** equivalent to a fresh Laravel 13 app with better tooling and a good engineering handbook. There is no domain code to inherit, which is fine given a fresh-database rebuild.
- **Frontend and design:** the most valuable artefact in either repository. It provides a tested component kit, money and bidi utilities, real RTL handling and a written vendor UX spec. **It is React, while the brief's stack baseline is Vue + Inertia** (brief §5 requires an ADR to replace an existing technology).
- **The frontend choice belongs to you (decision D-1):**
  - (a) **Vue, per the brief.** Port the React kit's *design*: tokens, component inventory, screen specs, copy. Roughly the kit has to be rebuilt.
  - (b) **React, via an ADR that supersedes the brief's stack line.** Reuse ~12k lines of tested UI directly.
- **My engineering recommendation is (b).** It reuses the most recent, highest-quality work and matches the latest owner decisions recorded in that repo. But it overrides the brief's text, so I will not assume it.
- **Prerequisites either way:**
  - Push or bundle the 61 local commits (A.3).
  - Track the design-handoff folder.
  - Decide author identity before the repo becomes the public portfolio (D-15).
  - Add `npm` type-check, lint and Vitest to its CI.

## A.5 Deployment workflows and the production database (review point 5)

### A.5.1 Facts (verified from workflow files and read-only `gh` queries)

**`deploy.yml` (production):**

- **Triggers:** `workflow_run` when **CI** completes successfully on `main`, or `workflow_dispatch` (input `environment`: `production`/`staging`).
- **Server sequence:** `git fetch origin main` → auto-`git stash push -u` of any server drift → `git merge --ff-only origin/main` → `composer install --no-dev` → caches → **`php artisan migrate --force` by default** (`run_migrations` defaults to `true`; automatic runs use `|| 'true'`).
- **Last run:** `32973123836`, 2026-08-26 13:15 UTC, `headSha 2a43b9d` = current `origin/main`.

**`deploy-staging.yml` (staging):**

- Its header says staging "**shares the production database (same tenants, orders, users)**".
- **Triggers:**
  1. `workflow_run` after CI completes. For this event type GitHub runs the workflow file from the default branch, and `origin/main` contains it. The job runs only if `conclusion == success && head_branch == 'redesign'`.
  2. `workflow_dispatch`.
- **Checkout:** `ref: redesign`. Server: `git fetch origin redesign` → auto-stash drift → `git checkout redesign` → `merge --ff-only`.
- **Assets:** rsynced to `STAGING_DEPLOY_PATH/public/build`. The web root's `index.php`/`.htaccess` are regenerated to point at the staging app.
- **Migrations:** `RUN_MIGRATIONS = inputs.run_migrations || 'false'`, so **off for automatic runs** and opt-in on dispatch.
- **Typesense:** `RUN_SCOUT_IMPORT` (opt-in) runs `scout:flush` + `scout:import` for Product, Category and Order.

**`ci.yml`:** runs on **every push to every branch**.

**Staging run history (all 7 runs, 2026-08-26):**

- `32982669102`, `32978174614`, `32972588491` (`workflow_run`) and `32974726689`, `32971048644` (dispatch) logged `RUN_MIGRATIONS: false`, with no executed migration output.
- `32970786677` and `32967584631` (dispatch) predate the migration step.
- **No staging run has executed migrations.** Staging code has been serving requests since 2026-08-26 against the production database *(per the workflow's own statement; the server `.env` was not inspected)*.

### A.5.2 How `redesign` can interact with production data

| Path | Trigger | Effect | Exposure |
|---|---|---|---|
| P1 Runtime writes | Any request to the staging site | Registrations, carts, checkouts, stock reservations, vendor-dashboard and Filament edits, queued jobs: all written to production tables by **unreviewed `redesign` code**, with every defect in §7–§8 | **Ongoing while staging is up** |
| P2 Schema change | Dispatch Deploy Staging with `run_migrations=true` | Runs `redesign` migrations on production. The two **uncommitted** migrations would run if they were committed and pushed. Production code (`main`) would then face an unknown schema. | Manual |
| P3 Auto-deploy | `git push origin redesign` → green CI | New code live against production data, **no human gate** (migrations off) | Any push to `redesign` |
| P4 Search wipe | Dispatch with `run_scout_import=true` | Flushes the Product/Category/Order indexes. If Typesense is shared *(unverified)*, production search is empty until re-import, which then comes from staging's code. | Manual |
| P5 Duplicate schedulers/queues | A cron/queue worker for the staging path *(unverified)* | Loyalty expiry, abandoned-cart e-mails and FX fetch run twice against the same data | Unknown |
| P6 Storage divergence | Uploads via staging | Production rows reference files that exist only in staging's storage | If used |
| P7 Merge to `main` | Merge `redesign` → `main` | Production deploy runs `migrate --force` **by default**, applying `redesign`'s migrations automatically | On merge |

**Not verifiable without server access:**

- The staging `.env` DB target.
- Whether staging cron or queue workers run.
- Whether Typesense, Stripe keys and webhooks are shared with production.

**Containment options (your decision; not executed):**

- Remove the `workflow_run` trigger from `deploy-staging.yml` (on `main`, since that copy governs `workflow_run`).
- Take staging offline, or give it its own database copy.

For v2: no workflow may reach this server's database (ADR-0018).

## A.6 Status of the old live storefront's risks (review point 6)

### A.6.1 Confirmed from code (not from the live server)

- The last production deploy shipped `origin/main @ 2a43b9d`.
- `git diff --stat origin/main redesign` is **empty** for every implicated path:
  - `app/Jobs`, `app/Domain/{Payment,Inventory,Order}`, `app/Infrastructure`, `app/Shared`.
  - `Web/Vendor*`, `Middleware/ResolveTenantFromUser.php`, `Api/V1/CheckoutController.php`.
  - `routes/web.php`, `routes/console.php`.
- The uncommitted WIP does not touch those paths either, except `routes/web.php`.
- **So production and staging run the same C1 (payment finalisation), C2 (dead outbox), C3 (inventory) and S3 (guest cart) code.**

### A.6.2 Correction to S1 for production

Evidence:

- On `origin/main`, the `tenant.subdomain` middleware is registered (`bootstrap/app.php`) but **applied to no route**. `git grep` finds no use in `routes/`. The storefront group uses only `locale`.
- Nothing else sets tenant context for web requests. `git grep "Context::add('tenant"` on `origin/main` finds only:
  - `ResolveTenantFromSubdomain` (unused).
  - `ResolveTenantFromUser` (vendor routes and API).
  - `FilamentTenantMiddleware`.
  - Two listeners/actions.
- So a shopper registering through `/{locale}/register` gets **`tenant_id = NULL`**: `BelongsToTenant`'s `creating` hook copies `Context::get('tenant_id')`, which is null.

Consequence: on `/vendor/*` (`auth` + `tenant.user`), `ResolveTenantFromUser` treats a NULL-tenant user like a super-admin and adds no context. `TenantScope` then applies **no filter**. **Any self-registered shopper can therefore read and modify every vendor's data:**

- Orders: any status, bypassing the state machine.
- Products and prices, and stock.
- Returns, reviews and Q&A.
- The customer list, which includes other users' names and e-mails.

This is **worse than the single-store exposure §8 first described**. Code-confirmed; not runtime-verified against the live site. (With the WIP applied, a shopper registered on a store's domain gets that store's `tenant_id`, which narrows exposure to that one store.)

### A.6.3 Not confirmed (needs your access; I used none)

- That the server's checked-out code equals `2a43b9d`. Deploys auto-stash server drift, so manual hot-fixes may have been stashed away.
- Live vs test Stripe keys, and whether real payments occurred.
- Webhook registration and queue worker (A.2 V3/V5).
- Whether real (non-seed) shoppers or vendors exist.

### A.6.4 Risk by scenario

| Risk | Live keys + real users | Test keys / demo only |
|---|---|---|
| C1 payment finalisation | Customers charged; orders stay `pending`; vendors see no paid orders; stock stays reserved; decline-then-success leaves charged customer with `failed` order | Functional bug |
| S1 vendor dashboard | Anyone who registers can operate **all** vendors' dashboards and read customer PII | Same exposure on demo data; still a public privacy/defacement risk |
| S3 guest cart takeover | Anonymous request can complete another user's cart and reserve stock | Low impact |

### A.6.5 Owner-side read-only checks (I will not run these without approval)

1. Stripe Dashboard: mode, payments, webhook delivery failures.
2. Read-only SQL:
   - `SELECT o.status, pi.status, COUNT(*) FROM orders o LEFT JOIN payment_intents pi ON pi.order_id = o.id GROUP BY o.status, pi.status;`
   - `SELECT COUNT(*) FROM users WHERE tenant_id IS NULL AND role_id IS NULL;` (self-registered shoppers able to reach `/vendor/*`)
3. Web-server access logs: `/vendor/` requests by non-staff sessions.

**Containment options (your decision; outside v2 scope):**

- Maintenance mode, or disable registration and checkout.
- Take staging offline.
- A minimal reviewed hot-fix for S1 (role check on the vendor route group) and C1.

## A.7 Target financial model, expanded (review point 7)

**Scope note.** This is a proposal for ADR-0012, not a decision. It assumes:

- One settlement currency, with amounts as integer minor units.
- The platform collects payment (Stripe test mode, "separate charges and transfers" semantics).
- Vendor payouts are **simulated** in the ledger until Stripe Connect is approved separately (brief §8: no real payouts before the model is defined).

### A.7.1 Entities

```
OrderGroup (one customer checkout) 1──* VendorOrder 1──* VendorOrderLine
   │ grand_total, currency                 │ commission_rate_bps (frozen at creation)
   │                                       │ per-line: net price, allocated_discount, tax, shipping share
   1──* PaymentAttempt (≤1 active)         1──* Fulfilment / Return
            │ provider_payment_id UNIQUE
            ▼
ProviderEvent (provider, event_id UNIQUE) ─► LedgerTransaction 1──* LedgerEntry(account, debit|credit, amount)
Refund (per VendorOrder, per line)        ─► idempotency_key UNIQUE, provider_refund_id UNIQUE
Dispute (per charge) ─► DisputeAllocation (per VendorOrder)
Payout (simulated, per vendor)            ─► from payable_available
```

### A.7.2 Chart of accounts (double-entry; every LedgerTransaction sums to zero)

| Account | Type | Meaning |
|---|---|---|
| `platform:psp_clearing` | Asset | Funds held for the platform at the provider |
| `platform:commission_revenue` | Revenue | Commission earned |
| `platform:psp_fees` | Expense | Processing fees |
| `platform:promo_expense` | Expense | Platform-funded discounts |
| `platform:dispute_holding` | Asset | Funds withdrawn by the provider pending a dispute |
| `platform:dispute_losses` | Expense | Disputes lost and absorbed by the platform |
| `vendor:{id}:payable_pending` | Liability | Owed to vendor, not yet withdrawable |
| `vendor:{id}:payable_available` | Liability | Withdrawable |
| `vendor:{id}:payable_held` | Liability | Frozen (open dispute or investigation) |
| `vendor:{id}:receivable` | Asset | Vendor owes the platform (refund or chargeback after payout) |
| `platform:tax_payable` *(only if the marketplace collects tax)* | Liability | Tax collected on behalf |

### A.7.3 Worked lifecycle (two vendors, 10 % commission)

Vendor A goods 60.00, Vendor B goods 40.00, charge 100.00, provider fee 3.20.

1. **Capture** (on `payment_intent.succeeded`, de-duplicated by `event_id`, amount and currency checked against the attempt):
   - Dr `psp_clearing` 100.00
   - Cr `vendor:A:payable_pending` 54.00 · Cr `commission_revenue` 6.00
   - Cr `vendor:B:payable_pending` 36.00 · Cr `commission_revenue` 4.00
2. **Fee** (from the provider's balance transaction): Dr `psp_fees` 3.20 / Cr `psp_clearing` 3.20. Who bears it is decision D-9.
3. **Maturity.** A's order is delivered and its return window (e.g. 7 days) has elapsed: Dr `A:payable_pending` 54.00 / Cr `A:payable_available` 54.00. The job is idempotent per VendorOrder.
4. **Simulated payout:** Dr `A:payable_available` 54.00 / Cr `psp_clearing` 54.00, plus a `Payout` row. Later, real transfers via Connect `transfers.create` with `source_transaction` and idempotency key = payout id.

### A.7.4 Partial vendor-order refund

Customer returns one Vendor A line worth 20.00.

- **Eligibility under a lock:**
  - Inside a transaction holding `SELECT … FOR UPDATE` on the VendorOrder:
    `refundable_remaining = captured_allocated(A) − Σ refunds(A) in {requested, approved, submitted, succeeded}`.
  - Per-line cap = line net after allocated discount, plus tax and shipping share per policy.
  - This serialises concurrent requests (fixes §7.5).
- **Lifecycle:** `requested → approved → submitted → succeeded | failed | requires_action`.
  - The provider call carries idempotency key = `refund.id`.
  - **A timeout leaves `submitted`, never `failed`.** Resolution comes from `refund.updated`/`charge.refunded` or reconciliation, so a retry cannot double-refund.
- **Ledger, posted only when the provider confirms success:**
  - Dr `A:payable_pending` (or `payable_available`) 18.00
  - Dr `commission_revenue` 2.00 (commission reversed pro-rata; decision D-10)
  - Cr `psp_clearing` 20.00
  - The original fee is normally not returned by the provider and stays expensed.
- **Already paid out:** the debit drives A's payable negative. The shortfall moves to `vendor:A:receivable` and is recovered by netting future payables (or by a transfer reversal once Connect exists).
- **Siblings untouched.** Vendor B's entries and order state never change. OrderGroup status is *derived* (`partially_refunded` when any child has refunds).
- **Inventory:**
  - Restock only on `ReturnReceived` (inspected).
  - A refund for a never-shipped item releases or consumes the reservation instead.
  - Shipping and tax refund portions follow the line's stored allocation.

### A.7.5 Discounts and promotions

- Group-level discounts are allocated to lines pro-rata to net amount, with largest-remainder rounding so they sum exactly.
- The allocation is stored on the line, so refunds reverse exactly what was allocated.
- **Funding source:**
  - Platform-funded: the vendor payable is computed on the pre-discount amount, and the platform posts Dr `promo_expense`.
  - Vendor-funded: the vendor payable is computed on the discounted amount.

### A.7.6 Commission

- `commission_rate_bps` is resolved at VendorOrder creation (vendor override → category → platform default) and **frozen**.
- It applies to line net after vendor-funded discounts, excluding tax. Shipping is included or excluded per D-10.
- Per-line rounding is half-even, with a documented residual-cent rule.
- Changing rates never alters existing orders; a test asserts this.

### A.7.7 Vendor payable balances

- Balances are **derived from ledger entries**, never stored as mutable columns. A cached projection is allowed, with a nightly consistency check.
- Vendor dashboard shows pending, available, held, receivable and paid, plus a statement export of ledger lines.
- Payout eligibility requires `available > 0` with no open dispute holds on the same orders. A minimum payout amount is a config value.

### A.7.8 Chargebacks and disputes

- **`charge.dispute.created`** (dispute is per charge, i.e. per OrderGroup):
  - The provider withdraws amount plus fee: Dr `dispute_holding` / Cr `psp_clearing`.
  - **Allocate** the disputed amount to VendorOrders: by the disputed items if identifiable, else pro-rata.
  - Move each allocation from `payable_*` to `payable_held`.
  - Notify the vendors and collect evidence through the vendor UI.
- **Won:** funds return (Dr `psp_clearing` / Cr `dispute_holding`), and holds are released to `available`.
- **Lost, per D-11:**
  - Vendor bears the goods amount: Dr `payable_held` / Cr `dispute_holding`, with any shortfall moving to `receivable`.
  - Or the platform bears it: Dr `dispute_losses`.
  - The dispute fee is normally platform-borne.
- **Guards:**
  - No refund while a dispute is open (prevents double recovery).
  - No payout of held funds.

### A.7.9 Reconciliation

1. **Internal invariants** (tests and a nightly job):
   - Every transaction balances.
   - Per OrderGroup: `captured = Σ vendor payables + Σ commission − promo_expense adjustments`.
   - `Σ refunds ≤ captured` per VendorOrder.
   - `paid` VendorOrders ⇔ a succeeded PaymentAttempt.
   - Reservations ⇔ unpaid open orders.
2. **Provider reconciliation** (daily):
   - Pull provider balance transactions for the window and match by payment, refund, dispute and transfer id.
   - Classify each as matched, missing-locally (replay from the provider object), missing-at-provider, or amount/currency mismatch.
   - Exceptions go to a `reconciliation_exceptions` queue with Filament resolve actions (audit-logged).
3. **Operational sweeps:**
   - Attempts stuck in `processing` beyond a threshold are re-fetched from the provider.
   - Expired reservations are released and their unpaid groups cancelled.
   - Refunds stuck in `submitted` are re-fetched.
4. **Reporting:** a daily settlement report (captures, refunds, fees, commission, payable movements, disputes) that ties out to the provider payout report.

### A.7.10 Testing implications

- **Property tests:** random baskets, discounts and partial refunds; the ledger always balances and allocations sum exactly.
- **MySQL concurrency tests:**
  - Two refunds on one VendorOrder.
  - Duplicate `event_id` delivery.
  - Refund racing payout maturity.
  - Dispute racing refund.
- **Failure tests with a fake provider adapter** (out-of-order, duplicated and delayed events; timeouts):
  - No double refund.
  - No payout of held funds.
  - Reconciliation detects an injected mismatch.

## A.8 What the brief already settles vs what needs your decision (review point 8)

### A.8.1 Settled by the brief (treated as given)

| Topic | Settled position | Brief § |
|---|---|---|
| Purpose & audience | Premium portfolio showcase for recruiters and engineers; must feel like a credible commercial product | header, §2 |
| Product model | Unified multi-vendor general-merchandise marketplace; three surfaces | §2, §6 |
| Process | One phase at a time; owner approval; ADRs for significant decisions; evidence-based reporting | §4, §19 |
| Strategy | Hybrid rebuild; **fresh database**; old app is a reference; never connect to the production DB | §3 |
| Architecture style | Modular monolith with explicit boundaries; no microservices | §5 |
| Order shape | Checkout/OrderGroup → per-vendor orders, designed explicitly | §8 |
| Payouts | No real vendor payouts until the financial model is defined | §8 |
| Admin | Evaluate and improve Filament rather than assume replacement | §6.3 |
| Technology changes | Any replacement of an existing technology needs an ADR | §5 |
| Visual identity | New from scratch; design system reviewed before frontend build | §7, Phase 2 |
| Quality | Concurrency, failure and authorization tests; CI gates must pass honestly | §10–§13 |
| Locales | Evaluate EN, AR (real RTL), MS | §16 |
| Docs layout & ADR template | As specified | §18 |

**Correction to §1 and §17.** I called "product context" (Q1) the single most important open question. The brief already settles it: the purpose is a portfolio showcase. What actually remains for you is D-4 (formally supersede the Sudan-market docs) and D-5 (currency and locale priority).

### A.8.2 Genuinely requires your decision

| ID | Decision | Options | Recommendation | Needed by |
|---|---|---|---|---|
| D-1 | Frontend framework | Vue 3 (brief) · React 19 (Nexo repo, via ADR) | React on reuse grounds, *or* Vue to follow the brief literally (A.4) | Phase 1 ADR |
| D-2 | v2 repository | New repo · continue `Sentients-AI/Nexo` · orphan branch in `nexo-ecommerce` | Continue `Sentients-AI/Nexo` after preservation, or a new repo if clean authorship matters | Phase 1 ADR |
| D-3 | Laravel/PHP version | Laravel 13 / PHP 8.5 · Laravel 12 / 8.4 | Laravel 13; PHP per your demo host | Phase 1 ADR |
| D-4 | Sudan-market product docs | Supersede · keep as a future context | Supersede for v2; archive | Phase 1 PRD |
| D-5 | Settlement currency & primary locale | MYR · USD · other; EN- or AR-primary | Single currency (MYR suits Malay locale and the existing demo vendor); EN primary with AR/MS as full peers | Phase 1 |
| D-6 | Payment depth | Test-mode Stripe + internal ledger + simulated payouts · Stripe Connect | The former; Connect as a later ADR | ADR-0011/12 |
| D-7 | v2 feature scope | Loyalty, referrals, bundles, gift cards, subscriptions, chat, Q&A, waitlist, flash sales, downloads | Keep promotions, reviews, wishlist; defer others | Phase 1 PRD |
| D-8 | Search | Typesense from day one · DB search behind a port first | DB first; Typesense in Phase 8 if justified | ADR-0015 |
| D-9 | Provider fees bearer | Platform · pro-rata to vendors | Platform | ADR-0012 |
| D-10 | Commission on refunds / on shipping | Reverse pro-rata or keep · include or exclude shipping | Reverse pro-rata; exclude shipping | ADR-0012 |
| D-11 | Chargeback liability | Vendor bears goods · platform bears | Vendor bears goods, platform bears fee | ADR-0012 |
| D-12 | Old live site & staging containment | Leave · maintenance · hot-fix S1/C1 · staging offline | **Urgent:** at minimum take staging offline and close `/vendor/*` to non-staff (A.6) | Now |
| D-13 | Preservation of uncommitted work | Plan A.3 steps 1–3 · plus step 4 | Steps 1–3 now | Now |
| D-14 | Stripe test-mode verification (A.2.2) | Now · Phase 5 | Phase 5, unless needed for D-12 | — |
| D-15 | Commit author identity for the public repo | Keep history · rewrite to one identity before publishing | Decide together with D-2 | Before Phase 3 |

---

**End of Phase 0 addendum A.**

---

# Addendum B — Owner decisions and their execution (2026-11-01, still Phase 0)

## B.1 Decisions recorded

| ID | Owner decision | Status |
|---|---|---|
| D-12 | Close public access to `/vendor/*` and take staging offline until its production-database connection is removed | **Approved. Not executed:** needs server access (B.3) |
| D-13 | Preservation steps 1–3, strictly read-only against both repositories | **Steps 1–2 executed and verified (B.2). Step 3 is yours** |
| D-1 | **Vue 3 + Inertia.js + TypeScript.** The existing React implementation is a reference, not the foundation | Approved; becomes ADR in Phase 1 |
| D-2 | **The clean `Nexo` repository is the Nexo 2.0 foundation.** The old repository is retained as a historical reference | Approved; becomes ADR in Phase 1 |

**Consequences of D-1 + D-2 together (for Phase 1 to plan, not acted on):**

- `Nexo` keeps its backend shell and tooling: Laravel 13, Larastan/PHPStan, Pint, Pest 5 with `arch()` presets, the engineering handbook, product and requirements docs, ADR-0001..0004 and CI.
- Its frontend layer gets replaced by Vue:
  - `@inertiajs/react` and React 19 give way to `@inertiajs/vue3`.
  - The Testing Library flavour changes, and `tsconfig`/ESLint configuration follows.
- The React vendor workspace (`feat/vendor-frontend`, 61 commits) becomes reference material. Its reusable assets are framework-neutral:
  - design tokens;
  - `lib/` money, format, status and countdown logic, portable to TS modules almost unchanged;
  - EN/AR locale files;
  - the component inventory and `vendor-frontend-spec.md`;
  - the design handoff folder.
- The old repo's Vue pages are reference only: they have 22 type errors and physical-direction CSS (§6).
- Phase 1 decides which `Nexo` branch v2 starts from. `main` holds the Phase 0 foundation, while `feat/vendor-frontend` adds the React UI that D-1 sets aside.
- D-15 (commit author identity) now applies to `Sentients-AI/Nexo`. It must be settled before that repository is presented publicly.
- This report lives, untracked, in the old repository. Moving it into `Nexo/docs/discovery/` is a Phase 1 housekeeping step.

## B.2 D-13 execution record (steps 1–2)

**Location:** `C:\laragon\backups\nexo-2026-11-01\` (9.8 MB, 144 files, outside both repositories).

**Method:** every git command ran with `GIT_OPTIONAL_LOCKS=0`, so not even the index was refreshed. Only read commands were used: `diff`, `ls-files`, `stash show`, `bundle create` (writes only the output file), `rev-parse` and `status`. The script is kept beside the scratch verification output; nothing was written inside either repository.

| Artefact | Contents |
|---|---|
| `old/redesign-tracked.patch` (69 KB) | `git diff --binary HEAD` of the 33 modified tracked files on `redesign@774d131` |
| `old/untracked/` (20 files) | All untracked files, paths preserved (includes this report) |
| `old/ignored/` | `.env`, `CLAUDE.md`, `Check.md`, `boost.json`, `.mcp.json`, `.claude/settings.local.json`, `.idea/`, `public/redesign/` |
| `old/stashes/stash-{0,1}.patch` | Both stashes, `--include-untracked`, binary |
| `old/agent-worktree/` | `agent-worktree.patch` (33 KB, 14 modified files) and `untracked/` (30 files) of `worktree-agent-a8ba81d6@5975250` |
| `old/nexo-ecommerce-all.bundle` | `git bundle --all`: every local and remote-tracking branch, including the worktree branch |
| `nexo/feat-vendor-frontend-tracked.patch` (7 KB) | The 3 modified tracked files on `feat/vendor-frontend@cc4e8bc` |
| `nexo/untracked/` (39 files) | Design handoff (`Form dependencies for artboards/…`), `.github/hooks/`, `hello.yaml`, swap file |
| `nexo/ignored/` | `.env`, `.idea/`, `.impeccable/` |
| `nexo/stashes/stash-0.patch` | The one stash |
| `nexo/nexo-all.bundle` | `git bundle --all`, including the **61 local-only commits** |
| `SHA256SUMS` | Checksums of every artefact |
| `SECRETS-WARNING.txt` | Both `.env` files are secrets: keep local, never upload or commit |

**Excluded on purpose** because they are regenerable: `vendor/`, `node_modules/`, `storage/`, `bootstrap/cache/`, `public/hot`, and `Nexo/build/` (a 69 MB PHPStan cache).

**Verification (step 2), all in a scratch folder:**

- `git bundle verify` reports "okay" for both bundles.
- Clone of the old bundle:
  - Check out `774d131`, then `git apply --check redesign-tracked.patch`: **applies**.
  - Check out `5975250`, then `git apply --check agent-worktree.patch`: **applies**.
- Clone of the Nexo bundle:
  - Check out `cc4e8bc`, then `git apply --check`: **applies**.
  - `origin/main..origin/feat/vendor-frontend` = **61 commits**, so the local-only work is captured.
- Untracked file counts match the live inventory: 20, 30 and 39.
- **Both repositories confirmed unchanged afterwards:**
  - old repo: `774d131 redesign`, 45 status entries, 2 stashes;
  - Nexo: `cc4e8bc feat/vendor-frontend`, 7 status entries, 1 stash.

**Step 3 (yours):** copy `C:\laragon\backups\nexo-2026-11-01\` to storage you control. Check it there with `sha256sum -c SHA256SUMS`. Prefer an encrypted location, since the folder holds the two `.env` files. Until this is done, the 61 commits still exist on one physical disk only.

## B.3 D-12: why it is not executed yet, and the runbook

**What I can and cannot reach:**

- Staging and production run on one server, which is reached over SSH. The connection details are GitHub secrets (`DEPLOY_HOST`, `DEPLOY_KEY`, `STAGING_DEPLOY_PATH`, …), visible in `deploy-staging.yml` lines 73–185. I have no server access and will not ask for keys.
- From here I can only:
  - disable GitHub workflows (B.3.3), which needs your go-ahead because it is an outward-facing change to the repo settings;
  - afterwards, check the public URLs with read-only `curl -I` requests.

**New finding while preparing the runbook (High, risk; depends on the server's `.env`):**

- `.env.example` sets `QUEUE_CONNECTION=database` and `CACHE_STORE=database`. If staging inherits these against the production database:
  - **Queue:** staging and production share one `jobs` table. A staging worker (running `redesign` code) can pick up and execute production jobs, including the Stripe webhook job, and the reverse is also true.
  - **Cache:** they share the `cache` table, including rate-limit counters, locks and cached config-derived values.
  - **Scheduler:** if staging has a `schedule:run` cron, scheduled tasks run twice against production data.
- This adds to A.5.2. It is one more reason the database connection, not just the website, must be cut.

### B.3.1 Staging offline (on the server, by you)

1. **Check the maintenance driver first:** `grep APP_MAINTENANCE_ "$STAGING_DEPLOY_PATH/.env"`.
   - It must be `file` (the default).
   - If it is `cache` with the `database` store, `artisan down` on staging **would also take production down**, because the flag would live in the shared cache table. In that case, skip step 2 and disable the staging subdomain in the hosting panel instead.
2. `cd "$STAGING_DEPLOY_PATH" && php artisan down`. In maintenance mode, queue workers stop pulling jobs and scheduled tasks are skipped.
3. Stop anything staging runs in the background:
   - staging queue workers (supervisor or panel);
   - any `schedule:run` cron line pointing at the staging path (`crontab -l`).
4. **Cut the database connection.** In the staging `.env`, blank `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`, or point them at a separate, empty staging database. Then run `php artisan config:clear`, because the deploy caches config.
5. If staging uses **the same MySQL user** as production, removing it from the staging `.env` is not enough. Give production its own user (or rotate the password and update **only** the production `.env`), so the old credentials can no longer reach the data.
6. Staging stays down until it has its own database. Under D-2 it will not be redeployed from the old repo at all.

### B.3.2 Close `/vendor/*` on production (on the server, by you, with no deploy)

**Why not a code hot-fix?** It would mean committing to `main`. A push to `main` triggers `deploy.yml`, which runs `migrate --force` by default (`run_migrations: default true`) and re-runs the Scout imports. That is a production deployment with migrations, which this phase must avoid.

**Use a web-server rule instead.** The site runs Apache (the deploy copies `public/.htaccess` into the web root), and nothing under `public/` is named `vendor`, so blocking the route prefix affects no assets. In the **web root** `.htaccess`, directly after `RewriteEngine On`, add:

```apache
# NEXO D-12 containment 2026-11-01: vendor workspace closed pending v2
# Optional staff allow-list: uncomment and set your IP
# RewriteCond %{REMOTE_ADDR} !^203\.0\.113\.10$
RewriteRule ^vendor(/.*)?$ - [F,L]
```

- This returns 403 for every `/vendor/*` request, including Inertia XHR requests. Filament admin (`/admin`), the storefront and checkout are untouched.
- The rule is undone by deleting those lines.
- **Every production deploy overwrites this `.htaccess`** (`cp public/.htaccess $DEPLOY_WEBROOT/.htaccess`). That is why B.3.3 matters.
- **Limitation:** this closes the hole for every vendor, legitimate ones included. Use the IP allow-list for yourself. Real vendor authorization belongs to v2.

### B.3.3 Freeze the old repository's deploy pipelines (I can do this, with your go-ahead)

```bash
gh workflow disable "Deploy Staging" -R AdilAzhari/nexo-ecommerce
gh workflow disable "Deploy"         -R AdilAzhari/nexo-ecommerce
```

- This stops a green CI on `redesign` from redeploying staging (which would undo B.3.1 and run `config:cache` against whatever `.env` is there).
- It stops a push to `main` from deploying, migrating and overwriting the B.3.2 `.htaccess`.
- It changes no code and no server, and `gh workflow enable` reverses it.
- CI, Auto Format and PR Checks remain active.

### B.3.4 Verification after B.3.1–B.3.3

I can run these read-only checks once you have done the server steps:

- `curl -sI https://store.aljebal-albeedos.com/vendor/dashboard` → expect `403`.
- `curl -sI https://store.aljebal-albeedos.com/` → expect `200` (storefront unaffected).
- Staging URL → expect `503` (maintenance) or unreachable. I need the staging hostname from you; it is a secret.
- `gh workflow list -R AdilAzhari/nexo-ecommerce` → both deploy workflows `disabled_manually`.

---

**End of Phase 0 addendum B. Phase 1 has not been started. Stopping for owner review.**
