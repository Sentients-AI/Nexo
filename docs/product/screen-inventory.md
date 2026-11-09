# Screen Inventory

- **Status:** Living document (Phase 3, M5). Update it in the PR that adds or removes a screen.
- **Date:** 2026-11-09

Every screen the customer and vendor journeys in [`user-journeys.md`](user-journeys.md) need, the layout it uses, and what it waits for. Platform staff screens (PJ-1…PJ-11) are Filament resources (ADR-0020) and are not listed. Every screen ships in en, ms and ar (NFR-I18N-01).

| Screen | Journeys | Layout | Status | Waits for |
|---|---|---|---|---|
| Home | CJ-1 | Storefront | Built (placeholder content) | Catalogue for featured content |
| Category listing with facets | CJ-1 | Storefront | Not started | Catalogue, ADR-0019 (search) |
| Search results | CJ-1 | Storefront | Not started | ADR-0019 |
| Product | CJ-1, CJ-5 | Storefront | Not started | Catalogue, ADR-0013 (money) |
| Store page (vendor storefront, follow) | CJ-1, CJ-6 | Storefront | Not started | Catalogue, ADR-0011 for follow |
| Cart (grouped by vendor) | CJ-1 | Storefront | Not started (header shows a placeholder count) | ADR-0013, ADR-0014 |
| Sign in, register, verify email (inline in checkout too) | CJ-1 4a–4d | Storefront, Checkout | Not started | ADR-0011 (M6) |
| Checkout: address, shipping, coupon, points, summary | CJ-1 5 | Checkout | Frame only (`/dev/layouts/checkout`, local) | ADR-0011, ADR-0015 |
| Payment (reservation timer, 3-D Secure) | CJ-1 6 | Checkout | Not started | ADR-0014, ADR-0015 |
| Confirming payment, then order confirmation | CJ-1 7 | Checkout | Not started | ADR-0012, ADR-0015 |
| Account: orders and shipment timelines | CJ-2, CJ-3 | Storefront | Not started | ADR-0011, ADR-0012 |
| Return request | CJ-4 | Storefront | Not started | ADR-0012, uploads (NFR-SEC-05) |
| Review form | CJ-5 | Storefront | Not started | ADR-0011 |
| Wishlist, followed stores, referrals | CJ-6 | Storefront | Not started | ADR-0011 |
| Sell on Nexo application | VJ-1 | Storefront | Not started | ADR-0011 |
| Vendor dashboard and readiness checklist | VJ-1 | Vendor | Frame only (`/dev/layouts/vendor`, local) | ADR-0011 (vendor context) |
| Store profile and shipping rates | VJ-1 | Vendor | Not started | ADR-0011 |
| Products, product editor with variant matrix, CSV import | VJ-2 | Vendor | Not started | ADR-0011, catalogue |
| Orders and VendorOrder detail | VJ-3 | Vendor | Not started | ADR-0011, ADR-0012 |
| Stock | VJ-4 | Vendor | Not started | ADR-0011, ADR-0014 |
| Returns | VJ-5 | Vendor | Not started | ADR-0011, ADR-0012 |
| Earnings, statement, payouts | VJ-6 | Vendor | Not started | ADR-0011, ADR-0016 |
| Team | VJ-7 | Vendor | Not started | ADR-0011 |
| Error pages: 403, 404, 419, 500, 503 | All | Own minimal frame (shared by both surfaces) | Built | — |
