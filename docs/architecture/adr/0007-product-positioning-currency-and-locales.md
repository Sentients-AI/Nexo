# ADR-0007: Position Nexo as a general marketplace in MYR, English-primary

- **Number:** 0007
- **Status:** Accepted, by owner decisions D-4 and D-5 (2026-11-01)
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

Nexo is a multi-vendor general-merchandise marketplace: many independent vendors, one storefront, one checkout. Before the specifications can name a currency, a payment provider or a locale set, the market has to be fixed, because each of those choices constrains the others.

Three of them pull hardest:

- **Payments.** The provider has to support the settlement currency, including card payments and 3-D Secure in test mode, or the payment design cannot be exercised end to end before launch. Stripe's published country list (mid-2026) rules out several otherwise attractive markets.
- **Money.** One settlement currency keeps allocation, rounding, refunds and reconciliation exact. A second currency means an FX policy, and the ledger has to carry it everywhere.
- **Language.** A storefront that only ever renders left to right hides a whole class of layout bugs until the first right-to-left locale arrives. Building RTL in from the start is far cheaper than retrofitting it.

## Problem

What product, market, currency and locale set do the Phase 1 specifications assume?

## Decision drivers

- A marketplace that holds up under real use: correct money, correct fulfilment, credible content.
- A payment provider that supports the currency, in test mode and live.
- A real RTL locale from day one (brief §16).
- One currency, to keep the financial model provable.

## Considered alternatives

### A. A single-country, Arabic-primary marketplace in a local currency without Stripe support

Rejected (D-4). Without a supported provider the payment design cannot be tested end to end, and it would need a second settlement path before launch.

### B. USD, English-primary

This was a viable option. Owner chose MYR instead.

### C. MYR, English-primary, with Arabic (RTL) and Malay as full peers (chosen)

- Stripe supports MYR, including card payments in test mode.
- Malay is the natural second locale for the market.
- Arabic is a genuine RTL locale, so every layout is built direction-agnostic from the first screen.

### D. Multi-currency display

Rejected. It needs an FX ADR and complicates the ledger and refunds, and the market does not need it.

## Decision

1. **Product:** Nexo, a single unified multi-vendor general-merchandise marketplace. It has three surfaces: customer storefront, vendor dashboard and platform administration.
2. **Settlement and display currency: MYR only.**
   - Amounts are stored as integer sen (see ADR-0013).
   - Every money column carries an explicit `currency` value, so a second currency later is an additive change.
3. **Locales:**
   - `en` is primary and the fallback.
   - `ar` (full RTL) and `ms` are full peers. Every UI string ships in all three before a feature is done.
4. **Prices are tax-inclusive.** Nexo v1 does not calculate, itemise or remit tax.
   - This is a stated product limitation, not an oversight.
   - A tax engine needs its own ADR.
5. **Market: Malaysia.**
   - Malaysian addresses and shipping zones: Peninsular, Sabah/Sarawak/Labuan.
   - Seeded vendors and customers are fictional.

## Positive consequences

- The payment design can be exercised end to end in Stripe test mode.
- One currency keeps allocation, rounding and reconciliation exact and testable.

## Negative consequences

- Arabic-speaking customers see prices in MYR. Acceptable for a Malaysian marketplace; display conversion would be an additive change.
- No tax handling. The README states it as a limitation.

## Risks

- Malaysian regulations (PDPA, SST registration) are out of scope for v1. Until live payments and those obligations have their own ADRs, the README says payments run in Stripe test mode.

## Testing implications

- Locale coverage tests: every translation key exists in `en`, `ar` and `ms`.
- RTL visual checks in Phase 9.
- Money formatting tests per locale.

## Migration implications

- The `REQ-VENDOR-*` identifiers are carried into the functional specification.

## Related

- ADR-0013 (money); `docs/product/product-requirements.md`.
