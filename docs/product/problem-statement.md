# Problem Statement

**Status:** Ratified 2026-08-30

---

## The shopper's problem

> A Sudan-market shopper struggles to buy online with confidence because storefronts are
> fragmented across social media and informal channels, online card payment is unreliable
> or untrusted, and few sites are properly localised in Arabic.

Consequences today: purchases happen over chat apps with manual bank transfers or cash;
no order history, no tracking, no recourse on a bad transaction.

## The vendor's problem

> A small Sudan-market vendor struggles to sell online because building a trusted
> storefront, integrating a payment provider, and handling fulfilment individually is
> beyond their means and skills.

Consequences today: the vendor's reach is limited to their existing social following;
every order is handled manually; no analytics; no way to build buyer trust from zero.

## How aljebal-albeedos solves it

> aljebal-albeedos runs **one** trusted, Arabic-first marketplace. Vendors list products
> against a shared catalog. Shoppers browse and buy across vendors in a single cart and
> checkout. The platform owns the payment relationship (Stripe), splits each purchase into
> one order per vendor, retains a commission, and settles each vendor for their portion.

### What the platform takes on so the vendor doesn't

| Concern | Platform provides |
|---------|-------------------|
| Trust | One brand, shared reviews, vendor approval, dispute handling |
| Payments | Single Stripe checkout for the shopper; Stripe Connect transfers to vendors |
| Localisation | Arabic-first UI, RTL layout, `SDG` currency, local conventions |
| Order plumbing | Cart, checkout, order lifecycle, shipment tracking, refunds |
| Discovery | Shared catalog, cross-vendor search, category browsing, store pages |
| Operations | Vendor dashboard: orders, inventory, earnings, analytics |

### What stays the vendor's responsibility

Pricing, stock accuracy, packing, handing parcels to a carrier, entering tracking numbers,
answering their own customer questions, and honouring returns.

## Why not an existing platform

- **Shopify / WooCommerce** — per-vendor storefront tools, not a shared marketplace; the
  shopper-trust and cross-vendor-cart problem is unsolved, and localisation/payment for
  Sudan is not addressed.
- **Regional marketplaces** — limited or no Sudan presence; not Arabic-first for this
  market's conventions.
- **Building it per-vendor** — recreates the exact problem this product exists to remove.

## Constraints that shape the solution

- **Payments:** Stripe + Stripe Connect only in V1. No cash-on-delivery in V1 (deferred —
  it is a known gap for this market and tracked in [scope.md](scope.md)).
- **Scale:** pilot volumes; see [goals.md](goals.md).
- **Infrastructure:** single VPS, SSH deploy. No container/managed-services investment in
  V1.
- **Team:** effectively one engineer plus the operator. Process exists to make that
  sustainable, not to coordinate a large team.
