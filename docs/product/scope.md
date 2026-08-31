# Scope

**Status:** Ratified 2026-08-30

Every feature area is tagged so a new developer knows what is load-bearing.

- **Core** — required for V1. Breaking it breaks the marketplace.
- **Extended** — built (from the SaaS era), kept, not on the V1 critical path.
- **Experimental** — built but shallow; keep dormant, do not invest, candidate for removal.
- **Deferred** — not built; explicitly not now.

---

## Core (V1 critical path)

| Area | Notes / delta from current code |
|------|--------------------------------|
| Shared catalog (products, variants, categories) | Storefront queries span all vendors — **not** vendor-scoped |
| Cross-vendor search (Typesense/Scout) | Already spans; drop any tenant filter on the storefront path |
| Multi-vendor cart | **New:** cart may hold items from multiple vendors |
| Split checkout | **New:** one basket → one payment → one `Order` per vendor. See ADR-0004 |
| Stripe Connect payments | **New:** one PaymentIntent for basket total; per-vendor `Transfer`; commission retained. See ADR-0005 (to be written) |
| Commission | **New:** platform-wide %, frozen onto each order at creation |
| Per-vendor order + fulfilment lifecycle | Existing `Order` state machine, now per-vendor sub-order |
| Inventory (reserve / release / movements) | Unchanged — keep as-is |
| Refunds (per sub-order, approval-gated) | Existing workflow; refund reverses that vendor's transfer |
| Vendor onboarding + operator approval | **Changed:** from instant self-serve trial to apply → approve → Connect onboarding |
| Vendor dashboard (orders, products, inventory, earnings, store page, settings) | Exists; rescope to per-vendor, add earnings-from-Stripe view |
| Platform operator control plane (Filament) | Exists; add vendor-approval + commission + payout-reconciliation |
| i18n / RTL (ar primary, en, ms) | Exists as scaffold; RTL needs real logical-property layout (redesign epic) |
| Observability (metrics, audit, alerts, correlation IDs) | Unchanged — keep |
| Transactional email | Unchanged |
| Shipment tracking (per sub-order) | Exists; ensure per-vendor |

## Extended (kept, not V1-critical)

| Area | Disposition |
|------|-------------|
| Loyalty points | Keep; make marketplace-wide (earn/redeem across vendors) |
| Referral codes | Keep; marketplace-wide. Known debt: `referee_coupon_code` not wired to Promotion engine |
| Wishlist | Keep |
| Saved addresses | Keep |
| Promotions / discounts | Keep, but **platform-run** in V1; per-vendor promo authoring is Extended-at-best |
| Product bundles | Keep only if single-vendor; cross-vendor bundles are out |
| Notification center (Reverb) | Keep |
| Bulk product CSV import | Keep — valuable for vendor onboarding |
| Vendor order CSV export | Keep |
| Store follow | New on `redesign` branch; keep as Extended |
| Sitemap / SEO meta | Keep |

## Experimental (dormant — do not invest)

Subscriptions (Cashier) · Gift cards · Promotion experiments (A/B) · AI product
recommendations · Real-time chat · Product Q&A · Back-in-stock waitlist · Abandoned-cart
recovery · Fraud dashboard (keep the signals, the dashboard is shallow) · Digital
products / downloads.

**Policy:** these must not block a Core change. If one becomes expensive to carry through
the marketplace migration, removing it is the default, recorded in an ADR.

## Deferred (not now — with the reason)

| Deferred | Reason | Revisit when |
|----------|--------|--------------|
| Cash-on-delivery | No non-Stripe settlement model yet; real gap for Sudan | After V1 launch, if adoption needs it |
| Custom payout wallet / escrow ledger UI | Stripe default payouts suffice at pilot scale | > ~200 orders/day or vendor complaints |
| Automated KYC workflow | Stripe Connect covers it | If Connect coverage proves insufficient |
| Local carrier + shipping-label integration (J&T, PosLaju, etc.) | Vendors self-manage carriers | Vendor demand + volume |
| Hyperlocal / same-day delivery | Not a pilot need | Post-pilot |
| Vendor sub-domains / white-label storefronts | Contradicts the one-storefront model | Never, unless the model changes |
| Designing for > pilot scale | [goals.md](goals.md) scale assumptions | When those numbers change |
| Second marketplace on the Nexo engine | Not a product goal | New product decision + ADR |

## How scope changes

1. A change to Core or to the [goals.md](goals.md) scale assumptions requires an ADR.
2. Promoting an Experimental feature to Extended/Core requires a one-paragraph
   justification in the backlog and operator sign-off.
3. This file is updated in the same PR as the change it describes.
