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
| L8 | Only `Vendors\Application\CreateVendor` creates a Vendor (Story #1; #29, owner decision D5) | PHPStan rule `Tests\Arch\PHPStan\VendorCreationRule`, registered in `phpstan.neon.dist`. It reports `new Vendor` (an anonymous subclass or a `class-string` of Vendor included), `Vendor::factory()`, `replicate()` and `newInstance()` on a Vendor, and a creating method (`create`, `make`, `firstOrNew`, `firstOrCreate`, `updateOrCreate`, `insert*`, `fillAndInsert*`, `upsert`, `updateOrInsert`, …) called on a Vendor, on `Vendor::`, on its builder or on a query over the `vendors` table. Methods that may insert as well as update count as creating. Its fixtures run in the Arch suite. Like L4, it sees only statically known code: not `save()` or `push()` (a new model cannot be told from a stored one, so the ways of getting an unsaved Vendor are reported instead), container resolution (`app(Vendor::class)`), `unserialize()` or reflection, a table name in a variable or aliased (`vendors as v`), raw SQL, code outside PHPStan's paths (tests, so a factory used by tests), or an ignore comment without an identifier |
| L9 | Only the audited orchestrators reach the audited Vendor operations: `CreateVendor::handle()` only from `RegisterVendor`, and `VendorLifecycle::approve()`, `suspend()` and `reactivate()` only from `ApproveVendor`, `SuspendVendor` and `ReactivateVendor` respectively (ADR-0023 Decision 7; #35) | PHPStan rule `Tests\Arch\PHPStan\VendorOrchestrationRule`, registered in `phpstan.neon.dist`. Each operation has exactly one allowed caller, and only in that caller's `handle()`, closures in it included; there is no module or namespace exemption. It reports a call of a protected operation on a receiver typed as `CreateVendor` or `VendorLifecycle`, however the instance was obtained (injected, `new`, `app(CreateVendor::class)`, nullsafe), and an array callable naming one with a constant method name (`[$lifecycle, 'approve']`). An orchestrator calling another operation, or its own from another method, is reported. Together with L4 and L8, every status write and creation they can see passes through an audited orchestrator. Its fixtures, the real orchestrators and `vendors:create` as the negative control, and temporary copies (another Vendors Application class, an Infrastructure class, a wrong operation, another method) run in the Arch suite. It sees only statically known code and shares the blind spots of L4 and L8: not a receiver of unknown type (`mixed`, a string container id), a first-class callable (`$lifecycle->approve(...)`, which L4, L8 and L10 do not see either), a dynamic method name or `call_user_func()` with one, reflection, raw SQL writing `vendors`, code outside PHPStan's paths (tests), or an ignore comment without an identifier. The primitives' own concurrency proofs call them directly under an identifier-specific ignore |
| L10 | `audit_log` is append-only, and `AuditRecorder::record()` is the only code that inserts into it: no other production code, `App\Shared\Audit` included, inserts, updates, deletes, truncates, upserts or replaces its rows (ADR-0023 Decision 8, "The recorder's single insert is the only allowed write"; #34) | PHPStan rule `Tests\Arch\PHPStan\AuditLogMutationRule`, registered in `phpstan.neon.dist`. Its one exemption is exact: a plain `insert()` on a query builder, written directly in the body of `AuditRecorder::record()`, not in a closure. Any other write in `record()`, any insert in another `AuditRecorder` method, and any insert in another `App\Shared\Audit` class is reported. It reports a writing method called on an audit model (an Eloquent model whose `$table` is `audit_log`; none exists), on `AuditModel::`, on its Eloquent builder or a relation onto it, or on a query builder chained from `table('audit_log')` or `from('audit_log')`, an `as` alias included. Writing methods are those that change or remove rows (`update`, `delete`, `truncate`, `upsert`, `updateOrInsert`, `updateOrCreate`, `increment` and `decrement` with their variants, `save`, `push`, `touch`, `forceDelete`, `destroy`, `restore`, …) and those that insert (`insert`, `insertGetId`, `insertOrIgnore`, `insertUsing`, `insertOrIgnoreUsing`, `fillAndInsert*`, `create`, `forceCreate`, `createMany`, `firstOrCreate`, `createOrFirst`, `saveMany`, …). It also reports constant SQL passed to `statement`, `unprepared`, `affectingStatement`, `update`, `delete` or `insert` on the `DB` facade or a connection, when the SQL names `audit_log` and contains `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE` or `REPLACE` outside string literals (`FOR UPDATE` excepted). Its fixtures, the real `App\Shared\Audit` as the negative control, and temporary copies of `AuditRecorder` and of another `App\Shared\Audit` class run in the Arch suite. It sees only statically known code: not a second plain `insert()` in the body of `record()` (the exemption is by place, not count), a table name or SQL held in a variable or built at run time, a query builder held in a variable before the call, a join onto `audit_log`, a schema-qualified table name in the builder, `setTable()`, container resolution, dynamic method names, schema-builder DDL (`Schema::drop()`), code outside PHPStan's paths (tests), or an ignore comment without an identifier. Constant writing SQL that only reads `audit_log` is reported too. This is an application convention checked statically, not database enforcement (OD-8): the application's database user can still insert, change and delete rows, and revoking UPDATE and DELETE belongs with deployment (ADR-0021) |
| L11 | Only membership use cases write protected membership fields. `vendor_id` and `user_id` are immutable after insertion (ADR-0011 Decision 2) | PHPStan rule `Tests\Arch\PHPStan\MembershipWriteRule`. It detects statically typed member models and builders, literal `vendor_members` query chains, recognized row writes and protected attribute writes. First-owner assignment may insert. The shared ownership transaction and deactivation may conditionally update state. Raw SQL, dynamic receiver types and names, dynamically built values, detached untyped query builders and code outside PHPStan paths are outside this proof. |

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
| C1 | No `App\Modules\*` or `App\Shared\*` class imports `App\Providers`, `App\Http` or `App\Models`. There is no exception: Identity's `App\Models\User` exception ended when `User` moved to `Identity\Domain\User` (ADR-0010 §2b; #33). |
| C2 | `App\Models` contains no class (`User` exited to `Identity\Domain\User`, #33), and `App\Http\Controllers` contains only `Controller`. The allowlist is closed; each exit removes its entry in the same change. |
| C3 | `App\Modules` contains only the 13 modules in the matrix above. Each contains only `Domain`, `Application`, `Infrastructure`, `Http`, `Contracts` and `Filament`, and each directory exists only once its first class is written. |

The composition root (`App\Providers`, kernel-level `App\Http`, `bootstrap/`, `routes/`) may import `App\Shared`, module `Contracts`, and module `Http`, `Filament` or `Infrastructure` classes only to register them.

## 3. Event subscriptions (asynchronous; no restriction on direction)

| Event (publisher) | Subscribers |
|---|---|
| `VendorApproved`, `VendorSuspended`, `VendorReinstated` (Vendors) | Catalogue (sellability cache), Search, Notifications |
| `ProductChanged`, `ProductBlocked/Unblocked` (Catalogue) | Search, Engagement (wishlist availability), Notifications (followers, S) |
| `StockLevelChanged` (Inventory) | Search |
| `CheckoutPlaced` (Ordering) | Notifications (none in v1: placeholder for an abandoned-payment reminder) |
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

## 7. Planned rules (not enforced)

No rules are currently listed here. L11 is enforced in §1.

| Rule | Statement | Planned enforcement | Lands with |
|---|---|---|---|
