# Initial Database Model

- **Status:** Draft for owner review (Phase 1). This is a logical model; migrations are written in Phases 3–6, one module at a time.
- **Date:** 2026-11-01
- **Engine:** MySQL 8.4, InnoDB, `utf8mb4_0900_ai_ci`, strict mode (ADR-0009)

## 1. Conventions

| Topic | Rule |
|---|---|
| Primary keys | `id BIGINT UNSIGNED AUTO_INCREMENT`. Internal only; never exposed to the browser. |
| Public identifiers | `public_id CHAR(26)` ULID, UNIQUE, on every entity addressed by URL (vendors use `slug`). Order references are human-readable and separately unique. |
| Money | `*_minor BIGINT` + `currency CHAR(3)` (ADR-0013). Rates are `*_bps INT UNSIGNED`. |
| Status | `VARCHAR(32)` + `CHECK (status IN (...))` matching the PHP enum, so a value outside the enum fails at the database |
| Time | `TIMESTAMP(6)` in UTC; `created_at`, `updated_at` on every mutable table. Append-only tables have only `created_at` / `occurred_at`. |
| Foreign keys | Always declared. `ON DELETE RESTRICT` by default; `CASCADE` only for pure child rows of the same aggregate (e.g. `cart_lines` of `carts`). Cross-module foreign keys are `RESTRICT` (ADR-0010). |
| Soft deletes | **Not used.** Lifecycle status (vendors, products), anonymisation (users) and archival (closed carts) replace them. Financial and order tables are never deleted. |
| Translations | Platform-owned names (categories, attributes, shipping zones) are stored as `JSON` `{en, ar, ms}`. Vendor-entered content is single-language `VARCHAR/TEXT`. |
| Ownership | Exactly one owning module per table (`domain-context-map.md` §2); the owner is noted below as **[Module]** |

## 2. Entity overview

```
users ─1:0..1─ vendor_members ─*:1─ vendors ─1:1─ vendor_profiles
  │                                   │ 1:*
  │ 1:*                               ├── products ─1:*─ variants ─1:1─ stock_levels ─1:*─ stock_reservations
addresses                             │      │                │                   └─1:*─ stock_movements
                                      │      └─1:*─ product_images      └─*:*─ attribute_values (via variant_attribute_values)
                                      └── vendor_shipping_rates ─*:1─ shipping_zones
categories (tree) ─1:*─ products

users ─1:*─ order_groups ─1:*─ vendor_orders ─1:*─ vendor_order_lines ─*:1─ variants
                 │                 │  ├─1:*─ shipments
                 │                 │  ├─1:*─ return_requests ─1:*─ return_request_lines
                 │                 │  └─1:*─ vendor_order_status_history
                 └─1:*─ payment_attempts ─1:*─ refunds(per vendor_order) ; disputes(per attempt)
provider_events (independent, unique event id)
ledger_transactions ─1:*─ ledger_entries ─*:1─ ledger_accounts ; payouts ─*:1─ payout_runs
```

## 3. Tables by module

Only key columns are listed. Every table also has `created_at`/`updated_at` unless it is marked append-only.

### Identity

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `users` | id, public_id, name, email, email_verified_at, password, locale (`en/ar/ms`), platform_role NULL (`admin/operations/finance/support`), two_factor_secret NULL, two_factor_confirmed_at NULL, anonymised_at NULL | UNIQUE email; CHECK locale; CHECK platform_role |
| `addresses` | id, user_id, recipient_name, phone, line1, line2, city, postcode (5 digits), state, zone_code, is_default | FK user CASCADE; INDEX (user_id); at most one default via `default_marker` (1/NULL) UNIQUE (user_id, default_marker) |
| `sessions`, `password_reset_tokens` | Laravel defaults | — |
| `staff_invitations` | id, email, platform_role, token_hash, expires_at, accepted_at | UNIQUE token_hash |

### Vendors

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `vendor_applications` | id, public_id, user_id, store_name, description, contact_phone, category_focus, status (`submitted/approved/rejected`), decided_by, decided_at, decision_reason | FK user; one open application per user: UNIQUE (user_id, open_marker) |
| `vendors` | id, public_id, slug, name, status (`pending/approved/suspended`), approved_at, suspended_at, suspension_reason | UNIQUE slug; CHECK status; INDEX status |
| `vendor_members` | id, vendor_id, user_id, role (`owner/manager/fulfilment/finance/support`), status (`active/deactivated`), owner_marker (1/NULL) | UNIQUE user_id (INV-2); UNIQUE (vendor_id, owner_marker) (one owner); FK vendor, user RESTRICT |
| `vendor_invitations` | id, vendor_id, email, role, token_hash, invited_by, expires_at, accepted_at, revoked_at | UNIQUE token_hash; INDEX (vendor_id, email) |
| `vendor_profiles` | vendor_id PK, tagline, description, logo_path, banner_path, return_policy, support_email | FK vendor CASCADE |

> **Note:** commission overrides are owned by Finance (`commission_rates`). `vendors` does **not** store them, which keeps `vendor-domain-design.md` §3 intact.

### Catalogue

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `categories` | id, public_id, parent_id NULL, slug, name JSON, depth (1–3), path (materialised, e.g. `/3/17/`), is_active, position | UNIQUE slug; CHECK depth BETWEEN 1 AND 3; INDEX path; FK parent RESTRICT |
| `attributes` | id, code, name JSON | UNIQUE code |
| `attribute_values` | id, attribute_id, code, label JSON, position | UNIQUE (attribute_id, code) |
| `products` | id, public_id, vendor_id, category_id, slug, title, description, brand NULL, status (`draft/active/inactive`), blocked_at NULL, blocked_reason NULL, published_at | UNIQUE (vendor_id, slug); INDEX (vendor_id, status); INDEX (category_id, status); FK vendor, category RESTRICT |
| `product_images` | id, product_id, path, alt, position, width, height | FK product CASCADE; UNIQUE (product_id, position) |
| `variants` | id, public_id, product_id, vendor_id (denormalised for scoping), sku, price_minor, compare_at_minor NULL, currency, weight_grams, is_active, option_signature (sorted value ids, e.g. `3-17`) | UNIQUE (vendor_id, sku); UNIQUE (product_id, option_signature); CHECK price_minor > 0; CHECK compare_at_minor IS NULL OR compare_at_minor > price_minor |
| `variant_attribute_values` | variant_id, attribute_value_id | PK pair; FK CASCADE |
| `product_moderation_events` (append-only) | id, product_id, action (`blocked/unblocked`), reason, actor_id, created_at | INDEX product_id |

### Inventory (ADR-0014)

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `stock_levels` | variant_id PK, vendor_id, on_hand INT UNSIGNED, reserved INT UNSIGNED, low_stock_threshold, version | CHECK reserved <= on_hand; INDEX vendor_id |
| `stock_reservations` | id, order_group_id, vendor_order_line_id, variant_id, qty, status (`active/consumed/released/expired`), expires_at, extended_at NULL | INDEX (status, expires_at); INDEX order_group_id; UNIQUE vendor_order_line_id |
| `stock_movements` (append-only) | id, variant_id, vendor_id, type (`receipt/adjustment/count/reservation/release/sale/cancellation_restock/return_restock`), delta_on_hand INT, delta_reserved INT, reference_type, reference_id, actor_id NULL, reason NULL, created_at | **UNIQUE (type, reference_type, reference_id, variant_id)**; INDEX (variant_id, created_at) |

### Shipping

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `shipping_zones` | id, code (`WM`, `EM`), name JSON, states JSON | UNIQUE code |
| `vendor_shipping_rates` | id, vendor_id, zone_id, rate_minor, free_over_minor NULL, currency | UNIQUE (vendor_id, zone_id) |

### Ordering

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `carts` | id, user_id NULL, session_token_hash NULL, status (`active/merged/converted/abandoned`) | UNIQUE (user_id, active_marker); UNIQUE session_token_hash |
| `cart_lines` | id, cart_id, variant_id, qty | UNIQUE (cart_id, variant_id); CHECK qty BETWEEN 1 AND 20; FK cart CASCADE |
| `order_groups` | id, public_id, reference (`NX-YYMMDD-XXXXXX`), customer_id, idempotency_key, status (`pending_payment/paid/expired/cancelled`), goods_minor, shipping_minor, discount_minor, points_value_minor, total_minor, currency, shipping_address JSON (snapshot), active_attempt_id NULL, promotion_id NULL, points_redeemed, expires_at, paid_at | **UNIQUE (customer_id, idempotency_key)**; UNIQUE reference; INDEX (status, expires_at); CHECK total_minor = goods_minor + shipping_minor − discount_minor − points_value_minor |
| `vendor_orders` | id, public_id, reference (`<group>-<n>`), order_group_id, vendor_id, status (ADR-0012), goods_minor, shipping_minor, discount_minor, points_value_minor, total_minor, commission_rate_bps, commission_minor, refundable_cap_minor, refund_committed_minor, currency, shipped_at, delivered_at, completed_at, return_window_ends_at, cancelled_reason | UNIQUE reference; UNIQUE (order_group_id, vendor_id); INDEX (vendor_id, status, created_at); CHECK refund_committed_minor <= refundable_cap_minor |
| `vendor_order_lines` | id, vendor_order_id, variant_id, product_title, variant_label, sku, unit_price_minor, qty, goods_minor, discount_allocated_minor, points_value_allocated_minor, commission_minor, refund_committed_qty, refund_committed_minor | CHECK refund_committed_qty <= qty; INDEX vendor_order_id |
| `vendor_order_status_history` (append-only) | id, vendor_order_id, from_status, to_status, actor_type (`system/customer/vendor_member/staff`), actor_id, reason, created_at | INDEX vendor_order_id |
| `shipments` | id, vendor_order_id, carrier, tracking_number, shipped_at | UNIQUE vendor_order_id (one shipment per VendorOrder in v2) |
| `return_requests` | id, public_id, vendor_order_id, customer_id, status (`requested/approved/rejected/received/closed/escalated`), reason_code, note, decided_by, decided_at, escalated_at | INDEX (vendor_order_id); INDEX (status, created_at) |
| `return_request_lines` | id, return_request_id, vendor_order_line_id, qty, received_qty, resellable_qty | CHECK received_qty <= qty |

### Payments

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `payment_attempts` | id, public_id, order_group_id, provider (`stripe/fake`), provider_payment_id NULL, amount_minor, currency, status (`created/creation_failed/requires_payment_method/requires_action/processing/succeeded/failed/cancelled`), failure_code, client_secret_encrypted | **UNIQUE provider_payment_id**; INDEX (order_group_id); INDEX (status, updated_at) |
| `provider_events` | id, provider, event_id, type, payload JSON, received_at, processed_at NULL, attempts, last_error | **UNIQUE (provider, event_id)**; INDEX (processed_at, received_at) |
| `refunds` | id, public_id, vendor_order_id, payment_attempt_id, amount_minor, currency, reason, initiator_type, initiator_id, status (`requested/approved/submitted/succeeded/failed`), provider_refund_id NULL, failure_code | UNIQUE provider_refund_id; INDEX (vendor_order_id); INDEX (status, updated_at) |
| `refund_lines` | refund_id, vendor_order_line_id, qty, amount_minor, commission_reversed_minor, discount_reversed_minor, points_value_reversed_minor | PK pair |
| `disputes` | id, payment_attempt_id, provider_dispute_id, amount_minor, reason, status (`open/won/lost`), evidence_due_at | UNIQUE provider_dispute_id |

### Finance (ADR-0016)

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `commission_rates` | id, scope (`default/category/vendor`), scope_id NULL, rate_bps, effective_from, created_by | UNIQUE (scope, scope_id, effective_from); CHECK rate_bps BETWEEN 0 AND 5000 |
| `ledger_accounts` | id, code (e.g. `vendor:42:payable_pending`), kind (`asset/liability/revenue/expense`), vendor_id NULL | UNIQUE code |
| `ledger_transactions` (append-only) | id, type, reference_type, reference_id, description, occurred_at, metadata JSON | **UNIQUE (type, reference_type, reference_id)** |
| `ledger_entries` (append-only) | id, transaction_id, account_id, direction (`debit/credit`), amount_minor, currency | CHECK amount_minor > 0; INDEX (account_id, id) |
| `payout_runs` | id, idempotency_key, period_end, status (`running/completed`), started_at, completed_at | UNIQUE idempotency_key; UNIQUE period_end |
| `payouts` | id, public_id, payout_run_id, vendor_id, amount_minor, currency, status (`simulated_paid`) | **UNIQUE (payout_run_id, vendor_id)** |
| `dispute_allocations` | id, dispute_id, vendor_order_id, amount_minor | UNIQUE (dispute_id, vendor_order_id) |
| `reconciliation_runs` | id, kind (`internal/provider`), window_start, window_end, status, summary JSON | — |
| `reconciliation_exceptions` | id, run_id, category, subject_type, subject_id, details JSON, status (`open/resolved`), resolved_by, resolution_note | INDEX (status) |

### Promotions

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `promotions` | id, public_id, name, code NULL, kind (`coupon/automatic`), discount_type (`percent/fixed`), value (bps or minor), min_spend_minor, max_discount_minor NULL, starts_at, ends_at, usage_limit NULL, per_customer_limit NULL, used_count, pending_count, is_active | UNIQUE code; CHECK used_count + pending_count <= COALESCE(usage_limit, ~0) is enforced by guarded update (CHECK with NULL logic documented in the migration) |
| `promotion_targets` | promotion_id, target_type (`category/vendor`), target_id | PK triple |
| `promotion_redemptions` | id, promotion_id, customer_id, order_group_id, status (`pending/confirmed/released`), discount_minor | UNIQUE (promotion_id, order_group_id) |

### Loyalty

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `points_entries` (append-only) | id, customer_id, type (`earn/redeem/release/clawback/referral_award/adjustment`), points (signed), reference_type, reference_id, created_at | UNIQUE (type, reference_type, reference_id); INDEX (customer_id, id) |
| `points_holds` | id, customer_id, order_group_id, points, status (`held/confirmed/released`) | UNIQUE order_group_id |
| `referral_codes` | id, customer_id, code | UNIQUE code; UNIQUE customer_id |
| `referrals` | id, referrer_id, referee_id, first_order_group_id NULL, status (`pending/qualified/awarded/void`) | UNIQUE referee_id |

### Engagement

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `reviews` | id, public_id, product_id, vendor_id, vendor_order_line_id, customer_id, rating, body, status (`published/hidden`), hidden_reason | **UNIQUE vendor_order_line_id**; CHECK rating BETWEEN 1 AND 5; INDEX (product_id, status) |
| `review_images` | id, review_id, path, position | — |
| `review_replies` | id, review_id, vendor_member_id, body | UNIQUE review_id |
| `wishlist_items` | customer_id, product_id | PK pair |
| `store_follows` | customer_id, vendor_id | PK pair |

### Search, Notifications, Shared

| Table | Key columns | Constraints and indexes |
|---|---|---|
| `search_documents` | product_id PK, vendor_id, category_path, title, brand, text_en, text_ar, text_ms, min_price_minor, max_price_minor, in_stock, rating_avg, rating_count, published_at | FULLTEXT (title, brand, text_en, text_ar, text_ms); INDEX (category_path), (min_price_minor), (vendor_id), (in_stock) |
| `notifications` | Laravel database notifications | — |
| `notification_preferences` | user_id, channel, category, enabled | PK triple |
| `outbox_messages` | id ULID, type, aggregate_type, aggregate_id, payload JSON, occurred_at, dispatched_at NULL, attempts, dead_at NULL, last_error | INDEX (dispatched_at, occurred_at) |
| `processed_messages` | consumer, message_id, processed_at | PK (consumer, message_id) |
| `idempotency_keys` | scope, key, user_id NULL, request_hash, response JSON, status_code, created_at, expires_at | PK (scope, key) |
| `audit_log` (append-only) | id, actor_id, impersonator_id NULL, action, subject_type, subject_id, before JSON, after JSON, ip, user_agent, created_at | INDEX (subject_type, subject_id); INDEX (actor_id, created_at) |
| `settings` | key, value JSON, updated_by | PK key |
| `jobs`, `failed_jobs`, `cache`, `cache_locks` | Laravel defaults (local/dev only; Redis in demo) | — |

## 4. Concurrency-relevant constraints (summary)

| Scenario (brief §10) | Primary protection | Secondary |
|---|---|---|
| Last unit / concurrent reservation | Conditional `UPDATE stock_levels … WHERE on_hand - reserved >= qty` | CHECK reserved <= on_hand |
| Duplicate checkout submission | UNIQUE (customer_id, idempotency_key) | Idempotency store replay |
| Simultaneous order transitions | `UPDATE … WHERE status = :from` | Status-history audit |
| Duplicate webhook | UNIQUE (provider, event_id) | Inbox; ledger UNIQUE (type, reference) |
| Repeated refund requests | Guarded `refund_committed_*` counters | Idempotency key; provider idempotency key |
| Concurrent vendor stock updates | Delta guarded update; absolute set with `version` CAS | Movement journal |
| Idempotency-key reuse with different payload | `idempotency_keys.request_hash` comparison → 422 | — |
| Failed transaction / rollback | Use-case transactions; outbox in same TX | Rollback tests |
| Concurrent payout runs | UNIQUE period_end / idempotency_key; UNIQUE (run, vendor) | Ledger UNIQUE |
| Promotion last use | Guarded counter update | Redemption UNIQUE |

## 5. Open points for Phase 3

- MySQL `CHECK` constraints that reference other columns work on 8.0.16+. Constraints that need cross-row logic stay in guarded updates.
- The `*_marker` (1/NULL) technique emulates partial unique indexes. It is documented in the migration comments.
- Whether `search_documents` keeps three per-locale text columns or one combined column is decided by search tests on Arabic fixtures.
