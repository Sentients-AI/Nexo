# ADR-0016: Account for money with an internal double-entry ledger; simulate vendor payouts

- **Number:** 0016
- **Status:** Proposed. The core direction (ledger + simulated payouts, no Stripe Connect in v2) was approved by the owner as D-6 on 2026-11-01. Rules 6–8 (D-9, D-10, D-11) await approval.
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

The brief (§8) requires the design to distinguish:

- customer collection;
- commission;
- vendor payables;
- transfers;
- refund liabilities;
- disputes;
- reconciliation.

It also forbids real payouts until the model is defined.

The old documents assumed Stripe Connect transfers with Stripe as the only financial record. The owner chose an internal ledger with simulated payouts (D-6). The full model, with worked examples, is in `docs/architecture/financial-model.md`. This ADR records the binding rules.

## Problem

Where is the financial truth recorded, and what are the rules for commission, payables, refunds, chargebacks and payouts?

## Decision drivers

- Every money movement is explainable and balances.
- Reconcilable against the provider.
- No real vendor transfers.

## Considered alternatives

### A. Stripe Connect as the financial record (old documents)

- Rejected (D-6).
- Needs Connect onboarding.
- Reconciliation and vendor statements depend on provider reports.

### B. Mutable balance columns on vendors

- Rejected.
- Not auditable.
- Races on concurrent updates.
- No explanation of a balance.

### C. Append-only double-entry ledger; balances derived (chosen)

## Decision

1. **Finance** module owns three tables:
   - `ledger_accounts`;
   - `ledger_transactions`, with columns `(id, type, reference_type, reference_id, occurred_at)` and **UNIQUE `(type, reference_type, reference_id)`**, so each business event posts at most once;
   - `ledger_entries`, with columns `(transaction_id, account_id, direction debit|credit, amount_minor > 0, currency)`.
2. **Every transaction balances** (Σ debits = Σ credits). This is checked in the posting service, and also by a nightly job. Entries are never updated or deleted. Corrections are new reversing transactions.
3. **Chart of accounts:**

   | Account | Kind |
   |---|---|
   | `platform:psp_clearing` | asset |
   | `platform:commission_revenue` | revenue |
   | `platform:psp_fees` | expense |
   | `platform:promo_expense` | expense |
   | `platform:loyalty_expense` | expense |
   | `platform:dispute_holding` | asset |
   | `platform:dispute_losses` | expense |
   | `vendor:{id}:payable_pending` | liability |
   | `vendor:{id}:payable_available` | liability |
   | `vendor:{id}:payable_held` | liability |
   | `vendor:{id}:receivable` | asset |

4. **Postings are triggered** by Payments and Ordering events through the outbox (ADR-0017), consumed idempotently. Each posting type is defined in `financial-model.md`:
   - capture;
   - fee;
   - refund;
   - maturity;
   - payout;
   - dispute opened, won and lost;
   - receivable netting.
5. **Commission:**
   - Rate resolution order: vendor override → top-level category rate → platform default (10 %).
   - The rate is frozen on the VendorOrder in basis points.
   - Computed per line on the goods amount *before* platform-funded discounts (ADR-0013).
6. **Fees (D-9, proposed):** the platform bears payment-processing fees. They are posted from the provider balance transaction to `psp_fees`. Vendors are not charged fees.
7. **Commission on refunds and shipping (D-10, proposed):**
   - A refund reverses the commission of the refunded lines exactly, using the stored per-line commission.
   - **Shipping is excluded** from commission. Shipping charged to the customer is credited 100 % to the vendor, who ships.
8. **Disputes (D-11, proposed):**
   - The disputed amount is allocated to VendorOrders and moved to `payable_held`.
   - If the dispute is lost, the vendor bears the goods amount (debit held; any shortfall becomes `receivable`).
   - The platform bears the dispute fee.
   - No refunds or payouts are allowed against held amounts.
9. **Promotions and loyalty** are platform-funded in v2:
   - vendor payable is calculated on pre-discount goods;
   - the discount is posted to `promo_expense` or `loyalty_expense`.
10. **Maturity:** when a VendorOrder becomes `completed` (return window elapsed), its pending payable moves to available. This is idempotent per VendorOrder.
11. **Simulated payouts:**
    - A weekly payout run is a scheduled job, also triggerable by Finance staff with an idempotency key.
    - For every vendor with available balance ≥ the minimum (RM 50.00, configurable), with any receivable netted first, it creates a `Payout(status=simulated_paid)` and posts Dr `payable_available` / Cr `psp_clearing`.
    - Nothing leaves the platform. The UI labels payouts "Simulated (demo)".
12. **Balances are derived** from entries. A cached projection (`ledger_account_balances`) may be added for speed only if it is verified nightly against entries.
13. **Reconciliation**, in three levels:
    - internal invariants;
    - provider reconciliation, which matches balance transactions daily;
    - operational sweeps.

    Exceptions go to `reconciliation_exceptions`, which Finance staff resolve in Filament. Every resolution is audit-logged.

## Positive consequences

- Every figure on vendor and finance screens is explainable line by line.
- The full model is testable without Stripe Connect.

## Negative consequences

- Owning accounting logic.
- Simulated payouts are visibly not real. That is acceptable and honestly labelled.

## Risks

- Posting rules wrong in edge cases (refund after payout, dispute after refund). Mitigation: property tests and the worked examples in `financial-model.md` as executable tests.

## Testing implications

- Property tests: random baskets, discounts, partial refunds and disputes; the ledger always balances; Σ refunds ≤ captured per VendorOrder.
- Concurrency tests: refund vs maturity; payout run executed twice concurrently; duplicate posting events.
- Each worked example in `financial-model.md` is an executable test.

## Migration implications

- Connect onboarding fields are removed from the vendor design.
- `INVARIANTS.md` gains the ledger invariants.
- ADR-0004's transfer rules are superseded via ADR-0012.

## Related

- `financial-model.md`, ADR-0013, ADR-0015, ADR-0017; Phase 0 §A.7.
