# Domain Context Map

- **Status:** Draft for owner review (Phase 1). This document is normative once ADR-0010 is accepted.
- **Date:** 2026-11-01

## 1. How boundaries were chosen

The brief (§8) says boundaries should follow these criteria:

- business ownership;
- consistency requirements;
- independent lifecycle;
- language.

The table records which criterion separates each module. Capabilities that share a consistency boundary and a lifecycle were merged rather than split. The brief lists about 20 capabilities; they map to **13 modules plus a shared kernel**.

| Brief capability | Module | Why here |
|---|---|---|
| Identity and access management | **Identity** | Accounts, authentication, staff roles; one owner of `users` |
| Vendor management | **Vendors** | Vendor lifecycle, applications, team membership, store profile; operator-owned lifecycle |
| Catalogue, categories and attributes, product variants, pricing | **Catalogue** | One consistency boundary: a variant's price, options and sellability change together. Pricing has no independent lifecycle in v2. |
| Inventory | **Inventory** | Different consistency needs (high-contention counters, reservations); single writer of stock |
| Shipping (rates) | **Shipping** | Vendor-configured rate tables and quoting. Shipments are part of Ordering (below). |
| Cart, checkout, orders, shipping and fulfilment (shipments), returns | **Ordering** | Cart → checkout → OrderGroup/VendorOrder → shipments → returns share the order lifecycle and language |
| Payments, refunds (provider side) | **Payments** | Provider interaction, attempts, provider events, refunds and disputes as provider objects; its own failure modes |
| Financial reporting, commissions, payouts | **Finance** | Ledger, commission policy, balances, payouts, reconciliation; accounting language and audit needs |
| Promotions | **Promotions** | Campaign lifecycle independent of orders; redemption limits need their own consistency |
| Loyalty (justified: owner D-7) | **Loyalty** | Points ledger and referrals; their own balance invariant |
| Reviews, wishlist, store follow | **Engagement** | Customer-generated content around products and stores; low consistency coupling |
| Search | **Search** | A read model only, rebuilt from events |
| Notifications | **Notifications** | Delivery channels and preferences; event subscriber only |
| Platform operations | **Shared** kernel (audit, settings) + Filament surfaces in each module | Not a business domain. Cross-cutting tooling must not become a "god module". |

## 2. Module responsibilities and owned data

| Module | Responsibilities | Owns (tables) |
|---|---|---|
| Identity | Registration, login, verification, 2FA (staff), profile, addresses, locale preference, platform staff roles | `users`, `addresses`, `password_reset_tokens`, `sessions`, `staff_invitations` |
| Vendors | Applications, vendor lifecycle, memberships and invitations, store profile, operating eligibility | `vendor_applications`, `vendors`, `vendor_members`, `vendor_invitations`, `vendor_profiles` |
| Catalogue | Category tree, attributes, products, variants, media, sellability, moderation | `categories`, `attributes`, `attribute_values`, `products`, `product_images`, `variants`, `variant_attribute_values`, `product_moderation_events` |
| Inventory | Stock levels, reservations, movements, adjustments, sweeper | `stock_levels`, `stock_reservations`, `stock_movements` |
| Shipping | Zones, vendor rate tables, quotes | `shipping_zones`, `vendor_shipping_rates` |
| Ordering | Carts, checkout placement, OrderGroups, VendorOrders, lines, status history, shipments, returns | `carts`, `cart_lines`, `order_groups`, `vendor_orders`, `vendor_order_lines`, `vendor_order_status_history`, `shipments`, `return_requests`, `return_request_lines` |
| Payments | Payment attempts, provider events, refunds, disputes (provider side), provider adapter | `payment_attempts`, `provider_events`, `refunds`, `refund_lines`, `disputes` |
| Finance | Commission policy, ledger, balances, payouts, reconciliation | `commission_rates`, `ledger_accounts`, `ledger_transactions`, `ledger_entries`, `payouts`, `payout_runs`, `dispute_allocations`, `reconciliation_runs`, `reconciliation_exceptions` |
| Promotions | Promotions, codes, eligibility, allocation, redemptions | `promotions`, `promotion_targets`, `promotion_redemptions` |
| Loyalty | Points ledger, holds, referral codes, referrals | `points_entries`, `points_holds`, `referral_codes`, `referrals` |
| Engagement | Reviews, replies, moderation, wishlist, store follows | `reviews`, `review_images`, `review_replies`, `wishlist_items`, `store_follows` |
| Search | Search documents, rebuild | `search_documents` |
| Notifications | Notification records, preferences, delivery | `notifications`, `notification_preferences` |
| Shared | Outbox, inbox, idempotency keys, audit log, settings | `outbox_messages`, `processed_messages`, `idempotency_keys`, `audit_log`, `settings` |

## 3. Relationships

**U** = upstream (supplier of the model). **D** = downstream (consumer). "Contract" means a synchronous `Contracts` interface. "Events" means asynchronous delivery through the outbox.

```
                         ┌───────────┐
                         │ Identity  │ U (customer/user ids, staff roles)
                         └─────┬─────┘
                               │ contract
          ┌────────────────────┼─────────────────────────────┐
          ▼                    ▼                             ▼
     ┌─────────┐ events   ┌───────────┐ contract (sellability, ┌──────────┐
     │ Vendors │────────► │ Catalogue │  variant snapshot)     │ Shipping │
     └────┬────┘          └─────┬─────┘──────────┐             └────┬─────┘
          │ contract (eligibility)│ events         ▼ contract         │ contract (quote)
          │                     │            ┌──────────┐           │
          │                     ▼            │ Ordering │◄──────────┘
          │               ┌───────────┐ contract (reserve/consume/release)
          │               │ Inventory │◄─────┤          │── contract (hold/confirm) ─► Promotions
          │               └───────────┘      │          │── contract (hold/confirm) ─► Loyalty
          │                                  │          │── contract (create attempt, request refund) ─► Payments
          │                                  └────┬─────┘
          │                                       │ events (VendorOrderPaid, Cancelled, Completed, ReturnReceived…)
          ▼                                       ▼
     ┌─────────┐   events (captured, refunded,  ┌─────────┐
     │ Finance │◄─ disputed, fees) ─────────────│Payments │──► contract: Ordering.markGroupPaid (inside the
     └─────────┘                                └─────────┘    payment-success transaction, see note)
     Search, Notifications, Engagement(rating aggregates) ◄── events from all of the above
```

**Note on the payment-success transaction.** Payments, Ordering, Inventory, Promotions and Loyalty commit **together** when a payment succeeds, because "paid without stock consumed" is not an acceptable intermediate state. This is the only cross-module synchronous transaction.

It is orchestrated by `Payments\Application\HandlePaymentSucceeded`, which calls these contracts within one database transaction:

- `Ordering\Contracts\OrderPayments::markPaid(groupId, attemptRef)`;
- that, in turn, calls Inventory, Promotions and Loyalty contracts.

Finance is **not** in this transaction. It posts from the outbox event, because a ledger lag of seconds is acceptable, whereas a lost posting is not, and the outbox guarantees the posting is never lost.

## 4. Ubiquitous language (glossary)

| Term | Meaning | Not to be confused with |
|---|---|---|
| Vendor | An independent seller entity with its own lifecycle | A user, a store page |
| Vendor member | A user's membership of one vendor, with a role | Platform staff |
| Customer | Any signed-in user acting as a buyer | Visitor (not signed in) |
| Product / Variant | A listing / a purchasable option combination with SKU, price and stock | — |
| Sellable | Product active ∧ not blocked ∧ vendor approved ∧ ≥ 1 active variant (INV-12) | "Active" alone |
| OrderGroup | One customer checkout and payment | VendorOrder |
| VendorOrder | One vendor's portion of an OrderGroup; the unit of fulfilment, refund and payable | — |
| Reservation | Stock held for an unpaid OrderGroup, with a TTL | Allocation |
| Payment attempt | One provider payment try for a group; several may exist over time, at most one active | Payment (the group's captured money) |
| Refund | A provider refund for part or all of one VendorOrder | Return (the goods workflow) |
| Return request | A customer's request to send goods back | Refund |
| Dispute | A chargeback raised through the provider on a group's charge | Return |
| Payable | Ledger liability owed to a vendor (pending, available, held) | Payout |
| Payout | A (simulated) settlement of available payable to a vendor | Refund |
| Commission | Platform revenue per line, at the frozen rate | Fee (provider's charge to the platform) |
| Allocation | The deterministic split of an amount across lines or orders | Reservation |
