# ADR-0007: Position Nexo as a portfolio-grade general marketplace in MYR, English-primary

- **Number:** 0007
- **Status:** Accepted, by owner decisions D-4 and D-5 (2026-11-01)
- **Date:** 2026-11-01
- **Decision owner:** Project owner
- **Supersedes:** the product framing in `docs/product/vision.md`, `scope.md`, `goals.md` and `personas.md` (ratified 2026-08-30), and `docs/architecture/marketplace-migration.md`

---

## Context

The existing product documents describe **aljebal-albeedos**:

- a Sudan-market, Arabic-first marketplace;
- settlement in SDG;
- Stripe Connect transfers paying each vendor;
- a migration plan for an existing live SaaS database.

The Nexo 2.0 brief describes something different:

- **Nexo**, a premium multi-vendor general-merchandise marketplace;
- built as a portfolio showcase that must feel like a credible commercial product;
- on a fresh database, with no connection to the old production data.

Phase 0 found (R2) that the Sudan + Stripe pairing was not viable as a real payment design. Stripe does not operate in Sudan, which is an assumption based on Stripe's published country list as of mid-2026.

## Problem

What product, market, currency and locale set do the Phase 1 specifications assume?

## Decision drivers

- Brief §2: the purpose is portfolio credibility for recruiters and engineers.
- A payment provider that genuinely supports the currency in test mode.
- A real RTL locale, to demonstrate internationalisation (brief §16).
- One currency, to keep the financial model provable.

## Considered alternatives

### A. Keep aljebal-albeedos, SDG, Arabic-primary

Rejected (D-4). This contradicts the brief's purpose, and SDG has no Stripe support.

### B. USD, English-primary

This was a viable option. Owner chose MYR instead.

### C. MYR, English-primary, with Arabic (RTL) and Malay as full peers (chosen)

- Stripe supports MYR, including card payments in test mode.
- Malay is a natural second locale.
- Arabic demonstrates genuine RTL.

### D. Multi-currency display

Rejected. It needs an FX ADR and complicates the ledger and refunds, with no showcase benefit proportional to the cost.

## Decision

1. **Product:** Nexo, a single unified multi-vendor general-merchandise marketplace. It has three surfaces: customer storefront, vendor dashboard and platform administration.
2. **Settlement and display currency: MYR only.**
   - Amounts are stored as integer sen (see ADR-0013).
   - Every money column carries an explicit `currency` value, so a second currency later is an additive change.
3. **Locales:**
   - `en` is primary and the fallback.
   - `ar` (full RTL) and `ms` are full peers. Every UI string ships in all three before a feature is done.
4. **Prices are tax-inclusive.** Nexo v2 does not calculate, itemise or remit tax.
   - This is a stated product limitation, not an oversight.
   - A tax engine needs its own ADR.
5. **Market framing for demo content: Malaysia.**
   - Fictional vendors and customers.
   - Malaysian addresses and shipping zones: Peninsular, Sabah/Sarawak/Labuan.
6. The previous product documents stay in the repository marked **Superseded** for history.

## Positive consequences

- The payment design can be exercised end to end in Stripe test mode.
- One currency keeps allocation, rounding and reconciliation exact and testable.

## Negative consequences

- Arabic users see prices in MYR. This is acceptable for a showcase.
- No tax handling. This is visible to a knowledgeable reviewer, so it is stated in the README limitations.

## Risks

- Malaysian regulations (PDPA, SST registration) are out of scope. All demo data is fictional. The README must say Nexo is not a production service.

## Testing implications

- Locale coverage tests: every translation key exists in `en`, `ar` and `ms`.
- RTL visual checks in Phase 9.
- Money formatting tests per locale.

## Migration implications

- Product documents are superseded. New `product-requirements.md` and `functional-requirements.md` replace them.
- The REQ-VENDOR-* identifiers are carried into the new functional specification.

## Related

- ADR-0013 (money); `docs/product/product-requirements.md`; Phase 0 report §A.8.
