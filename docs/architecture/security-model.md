# Security Model: Roles, Permissions and Isolation

- **Status:** Draft for owner review (Phase 1). It implements ADR-0011.
- **Date:** 2026-11-01

## 1. Principals

| Principal | How established | Surface |
|---|---|---|
| Visitor | No session user | Storefront (read), guest cart |
| Customer | Authenticated user without `platform_role` | Storefront, account |
| Vendor member | Customer with an active `vendor_members` row; `VendorContext` resolved per request | `/vendor` |
| Platform staff | User with `platform_role` and confirmed 2FA | `/admin` (Filament) |
| System | Scheduler, queue jobs, webhook processor; recorded as `actor_type = system` | — |

## 2. Permission matrix: vendor roles

✓ means allowed. Ownership (`resource.vendor_id = context.vendor_id`) is always required in addition to the permission.

| Permission | Owner | Manager | Fulfilment | Finance | Support |
|---|---|---|---|---|---|
| `vendor.dashboard.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `vendor.products.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `vendor.products.manage` (create, edit, activate, import) | ✓ | ✓ | | | |
| `vendor.inventory.adjust` | ✓ | ✓ | ✓ | | |
| `vendor.orders.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `vendor.orders.fulfil` (processing, shipped, delivered) | ✓ | ✓ | ✓ | | |
| `vendor.orders.cancel` | ✓ | ✓ | | | |
| `vendor.returns.decide` | ✓ | ✓ | | | ✓ |
| `vendor.returns.receive` | ✓ | ✓ | ✓ | | |
| `vendor.refunds.goodwill` (capped at the refundable remainder) | ✓ | | | ✓ | |
| `vendor.finance.view` (balances, statement, payouts) | ✓ | | | ✓ | |
| `vendor.reviews.reply` | ✓ | ✓ | | | ✓ |
| `vendor.store.manage` (profile, shipping rates) | ✓ | ✓ | | | |
| `vendor.team.manage` | ✓ | | | | |

**Suspended vendor overlay** (`vendor-domain-design.md` §8). For a suspended vendor:

- `products.manage` is denied;
- new orders cannot arrive, because the vendor's products are not sellable;
- `orders.fulfil`, `orders.view`, `finance.view` and `returns.*` stay allowed.

A `pending` vendor can manage its profile, shipping and draft products, but cannot activate them.

## 3. Permission matrix: platform roles

| Permission | Admin | Operations | Finance | Support |
|---|---|---|---|---|
| `platform.staff.manage` | ✓ | | | |
| `platform.impersonate` (customers and vendor members only) | ✓ | | | |
| `platform.vendors.review` (applications, approve, suspend, reinstate) | ✓ | ✓ | | |
| `platform.catalogue.manage` (categories, attributes) | ✓ | ✓ | | |
| `platform.catalogue.moderate` (block or unblock products, hide reviews) | ✓ | ✓ | | |
| `platform.orders.view` | ✓ | ✓ | ✓ | ✓ |
| `platform.orders.intervene` (cancel a VendorOrder, override returns) | ✓ | ✓ | | |
| `platform.refunds.override` (create or retry refunds) | ✓ | | ✓ | |
| `platform.disputes.manage` | ✓ | | ✓ | |
| `platform.finance.view` (ledger, balances, dashboards) | ✓ | | ✓ | |
| `platform.finance.commission` | ✓ | | ✓ | |
| `platform.finance.payouts` (trigger run) | ✓ | | ✓ | |
| `platform.finance.reconcile` (resolve exceptions) | ✓ | | ✓ | |
| `platform.promotions.manage` | ✓ | ✓ | | |
| `platform.loyalty.manage` (rates, manual adjustment) | ✓ | | ✓ | |
| `platform.customers.view` | ✓ | ✓ | ✓ | ✓ |
| `platform.audit.view` | ✓ | | ✓ | |
| `platform.health.view` (outbox, jobs, webhooks; retry dead letters) | ✓ | ✓ | | |

**Separation of duties.** Finance cannot change catalogue, and Operations cannot move money. Only Admin holds both, and every Admin action is audit-logged.

## 4. Customer rules

- A customer can access only their own carts, orders, returns, reviews, wishlist, follows, points and addresses.
- Order, return and refund URLs use `public_id` or the reference, and are always looked up **scoped to the authenticated customer**. Another customer's reference returns 404.
- A customer can review only lines they bought and received (INV, FR-REV-01).

## 5. Enforcement points

| Layer | Mechanism |
|---|---|
| Routing | Route groups: `storefront` (web), `account` (auth + verified), `vendor` (auth + verified + `vendor.context`), `admin` (Filament panel + 2FA), `webhooks` (signature middleware, CSRF-exempt) |
| Binding | Vendor routes use scoped bindings: `{vendorOrder}` resolves `where vendor_id = context`. Customer routes resolve `where customer_id = auth id`. |
| Policies | One policy per aggregate; `before()` never grants a blanket allow, not even for Admin (Admin is granted explicitly per permission) |
| Use cases | Accept `Actor` (user, role, vendor context, impersonator) and re-check the permission. Use cases may be called from Filament, jobs or controllers, so the check lives in the use case as well as in the controller. |
| Database | Ownership columns are NOT NULL; foreign keys use RESTRICT |
| Frontend | Hides unavailable actions using server-computed `can.*` props. This is never relied on for security. |

## 6. Threat-driven controls (summary)

| Threat | Control |
|---|---|
| Cross-vendor access (Phase 0 S1) | Fail-closed VendorContext, scoped bindings, isolation suite |
| IDOR on customer resources | Scoped lookup by public id, plus tests |
| Price or quantity tampering | Server re-pricing; client totals only compared, never trusted |
| Forged webhooks | Signature verification with a tolerance window; 400 on failure |
| Replayed requests | Idempotency keys; unique provider ids |
| Account takeover | Rate limits; email verification; staff 2FA; session regeneration on login; password rules (min 12, breached-password check via `Password::uncompromised()`) |
| Privilege escalation through role edits | Roles in code; membership changes only by the Owner via a use case; staff roles only by Admin |
| Malicious uploads | MIME sniffing, re-encode, size limits, private storage until processed |
| Information leakage | Generic production errors (`APP_DEBUG=false`); vendors see minimal customer PII (NFR-PRIV-02) |
| Impersonation abuse | Admin-only, audited, banner, money actions blocked |
| Secrets exposure | Live-key boot refusal; secret scanning in CI |

## 7. Audit events (INV-19)

These actions are recorded:

- vendor application decisions;
- vendor lifecycle changes;
- membership changes;
- product block and unblock;
- review hide and unhide;
- commission changes;
- refund create, retry and override;
- dispute outcomes;
- payout runs;
- reconciliation resolutions;
- return overrides;
- staff create, change and deactivate;
- impersonation start and stop;
- loyalty manual adjustments.
