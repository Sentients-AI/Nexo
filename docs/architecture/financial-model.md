# Financial Model

- **Status:** Draft for owner review (Phase 1). It implements ADR-0016 and ADR-0013. It develops Phase 0 §A.7.
- **Date:** 2026-11-01
- **Assumptions pending approval:** D-9 (the platform bears payment fees), D-10 (commission reversed exactly; shipping excluded from commission), D-11 (the vendor bears lost-dispute goods value; the platform bears the dispute fee)

All amounts are MYR. They are shown in RM for readability and stored in sen.

## 1. Principles

1. **Customer money is collected once per OrderGroup**, in Stripe test mode on the platform account.
2. **The internal ledger is the financial record.** Stripe is reconciled against it, not the other way round.
3. **Vendor payable** for a line is the *pre-discount* goods value minus commission, plus the VendorOrder's shipping. Promotions and points are platform-funded in v2.
4. **Commission** is the frozen basis-point rate × the line's pre-discount goods value, rounded half-even per line. It excludes shipping (D-10).
5. **Every posting is a balanced transaction**, unique per (type, reference). Corrections are new transactions.
6. **Loyalty points are not a ledger liability** in v2. Their money value is recognised as `loyalty_expense` when redeemed. This simplification is documented and acceptable for a showcase: unredeemed points have no cash value.

## 2. Accounts

| Account | Kind | Increases with | Meaning |
|---|---|---|---|
| `platform:psp_clearing` | Asset | Debit | Money held for the platform at Stripe |
| `platform:commission_revenue` | Revenue | Credit | Commission earned |
| `platform:psp_fees` | Expense | Debit | Stripe processing fees (D-9) |
| `platform:promo_expense` | Expense | Debit | Platform-funded discounts |
| `platform:loyalty_expense` | Expense | Debit | Points redeemed at checkout |
| `platform:dispute_holding` | Asset | Debit | Funds withdrawn by Stripe pending a dispute |
| `platform:dispute_losses` | Expense | Debit | Dispute fees and platform-borne losses |
| `vendor:{id}:payable_pending` | Liability | Credit | Owed, not yet withdrawable (before completion) |
| `vendor:{id}:payable_available` | Liability | Credit | Withdrawable at the next payout |
| `vendor:{id}:payable_held` | Liability | Credit | Frozen by an open dispute |
| `vendor:{id}:receivable` | Asset | Debit | Vendor owes the platform (refund or dispute after payout) |

## 3. Posting catalogue

| Type | Trigger (event) | Reference | Entries |
|---|---|---|---|
| `capture` | `PaymentCaptured` | payment attempt | Dr `psp_clearing` total; Dr `promo_expense` Σ discount; Dr `loyalty_expense` Σ points value; Cr each vendor `payable_pending` (goods − commission + shipping); Cr `commission_revenue` Σ commission |
| `psp_fee` | `ProviderFeeRecorded` | balance transaction | Dr `psp_fees` / Cr `psp_clearing` |
| `refund` | `RefundSucceeded` | refund | Cr `psp_clearing` refund amount; Dr `commission_revenue` commission reversed; Cr `promo_expense` discount reversed; Cr `loyalty_expense` points value reversed; Dr vendor payable (pending → available → receivable, in that order) for the balancing amount |
| `maturity` | `VendorOrderCompleted` | vendor order | Dr `payable_pending` / Cr `payable_available` for that VendorOrder's net remaining payable |
| `payout` | Payout created | payout | Dr `payable_available` / Cr `psp_clearing` |
| `receivable_netting` | Payout run (before payout) | payout | Dr `payable_available` / Cr `receivable` (up to the available amount) |
| `dispute_opened` | `DisputeOpened` | dispute | Dr `dispute_holding` / Cr `psp_clearing` (disputed amount); Dr `dispute_losses` / Cr `psp_clearing` (fee); per allocation, move vendor payable → `payable_held` |
| `dispute_won` | `DisputeWon` | dispute | Dr `psp_clearing` / Cr `dispute_holding`; per allocation `payable_held` → origin bucket (`pending` if the VendorOrder is not completed, else `available`) |
| `dispute_lost` | `DisputeLost` | dispute | Per allocation: Dr `payable_held` / Cr `dispute_holding` (D-11; any shortfall moves to Dr `receivable`) |

**Refund balancing amount:** vendor debit = refund amount − commission reversed + discount reversed + points value reversed. It is computed from stored per-line allocations, so it is exact.

## 4. Worked example (an executable test in Phase 5)

**Setup**

- Commission is 10 % for both vendors.
- Coupon RM 10.00, platform-funded.
- No points.

| Line | Vendor | Goods | Allocated discount (largest remainder, pro-rata to goods) | Commission |
|---|---|---|---|---|
| A1 | A | 40.00 | 4.00 | 4.00 |
| A2 | A | 20.00 | 2.00 | 2.00 |
| B1 | B | 40.00 | 4.00 | 4.00 |

Shipping: A RM 8.00, B RM 5.00.

**Charged:** 100.00 + 13.00 − 10.00 = **RM 103.00**

**4.1 Capture**

| Account | Dr | Cr |
|---|---|---|
| psp_clearing | 103.00 | |
| promo_expense | 10.00 | |
| A:payable_pending (60 − 6 + 8) | | 62.00 |
| B:payable_pending (40 − 4 + 5) | | 41.00 |
| commission_revenue | | 10.00 |
| **Total** | **113.00** | **113.00** |

**4.2 Fee.** Assume a Stripe fee of RM 4.09: Dr psp_fees 4.09 / Cr psp_clearing 4.09. The platform bears it (D-9). Vendor payables are unchanged.

**4.3 Partial refund: A2 returned.** The customer paid 20.00 − 2.00 = **18.00** for A2. Shipping is not refunded because other A lines remain.

| Account | Dr | Cr |
|---|---|---|
| psp_clearing | | 18.00 |
| commission_revenue (A2's commission) | 2.00 | |
| promo_expense (A2's discount) | | 2.00 |
| A:payable_pending (18 − 2 + 2 = 18) | 18.00 | |
| **Total** | **20.00** | **20.00** |

A's pending payable is now 44.00 = (40 − 4) + 8. B is untouched (INV-4).

**4.4 Vendor B cancels before shipping.** The full VendorOrder is refunded, including shipping: 40 − 4 + 5 = **41.00**.

| Account | Dr | Cr |
|---|---|---|
| psp_clearing | | 41.00 |
| commission_revenue | 4.00 | |
| promo_expense | | 4.00 |
| B:payable_pending (41 − 4 + 4) | 41.00 | |
| **Total** | **45.00** | **45.00** |

B's payable is 0.00. Platform result on B is 0: commission and promo expense are both reversed.

**4.5 Maturity.** A's VendorOrder completes: Dr A:payable_pending 44.00 / Cr A:payable_available 44.00.

**4.6 Payout run.** Available 44.00 ≥ minimum 50.00? **No.** A is skipped this week (P-7 minimum), and the balance carries forward. This is a deliberate test of the threshold rule.

**4.7 Goodwill refund after a payout** (separate scenario).

- Vendor A has available 0.00 and has already been paid out.
- Vendor Finance grants RM 10.00 goodwill on A1. A1's paid amount was 36.00, with commission 4.00 and discount 4.00.
- Pro-rata reversal, half-even: commission 4.00 × 10/36 = 1.11; discount 1.11.
- Vendor debit = 10.00 − 1.11 + 1.11 = 10.00. No payable remains, so the debit goes to **receivable**:

| Account | Dr | Cr |
|---|---|---|
| psp_clearing | | 10.00 |
| commission_revenue | 1.11 | |
| promo_expense | | 1.11 |
| A:receivable | 10.00 | |
| **Total** | **11.11** | **11.11** |

At the next payout run, `receivable_netting` recovers it from A's available balance before any payout.

**Refund cap check (INV-5).** A1 refundable = 36.00. After this refund, 26.00 remains.

## 5. Disputes (D-11)

- A dispute is raised on the Stripe charge, so it is per OrderGroup.
- **Allocation:**
  - if the dispute reason and evidence identify specific VendorOrders (Finance staff selects them), the amount is allocated to those;
  - otherwise it is allocated pro-rata to the VendorOrders' remaining captured amounts, by largest remainder.
- **Opened:**
  - funds move to `dispute_holding`;
  - the dispute fee (assume RM 15.00 test value) goes to `dispute_losses`;
  - each allocation moves from the vendor's pending or available payable to `payable_held`. If the vendor's payable is insufficient (already paid out), the shortfall is recorded but not held, and on loss it becomes receivable.
- **Won:** the funds return; holds are released to their origin bucket.
- **Lost:** the held amount is consumed against `dispute_holding`, so the vendor bears the goods value. The fee stays a platform loss.
- **Guards:**
  - refunds on a disputed group are blocked;
  - payouts exclude held balances;
  - maturity of a VendorOrder with an open dispute allocation waits until the dispute closes.

## 6. Balances and statements

- **Vendor balance by bucket** is Σ credits − Σ debits on that bucket's account. Receivable is Σ debits − Σ credits.
- The **vendor statement** lists every ledger entry on the vendor's accounts with:
  - date;
  - type;
  - reference (VendorOrder, refund, dispute or payout reference, from transaction metadata);
  - amount;
  - running balance per bucket.

  It is exportable to CSV. The statement totals equal the balances (FR-FIN-03).
- **Platform finance dashboard:**
  - GMV = Σ captured;
  - net revenue = commission − promo − loyalty − fees − dispute losses;
  - refunds;
  - outstanding payables;
  - receivables.

## 7. Payout run

1. Scheduled Monday 09:00 MYT, or triggered by Finance staff. Idempotency key `payout-run:{period_end}`, with UNIQUE `period_end`.
2. For each vendor, under a per-vendor lock:
   1. net the receivable against available;
   2. if available ≥ RM 50.00, create `Payout(simulated_paid)` and post `payout`.
3. Notify vendors.
4. Concurrency: running the payout twice concurrently gives one run. The second gets a unique violation and returns the existing run.

## 8. Reconciliation

| Level | Frequency | Checks | On failure |
|---|---|---|---|
| Internal | Nightly + in tests | Every transaction balances; per group, captured = Σ vendor payable credits + commission − promo − loyalty (at capture); per VendorOrder, Σ refunds ≤ refundable cap; paid groups ⇔ one succeeded attempt; balance projections = entry sums | `reconciliation_exceptions` (category `internal`) and an alert |
| Provider | Daily, for the previous day (MYT) | Match Stripe balance transactions (charges, refunds, fees, disputes) to attempts, refunds, disputes and fee postings by provider id; compare amount and currency | Exceptions: `missing_locally` (replay from provider), `missing_at_provider`, `amount_mismatch` |
| Operational | Every 5 min | Attempts in `processing`/`requires_payment_method` > 30 min → re-fetch; refunds `submitted` > 30 min → re-fetch; provider events unprocessed > 5 min → re-dispatch | Escalate after 3 tries |

Finance staff resolve exceptions in Filament with a note. Resolution may trigger a corrective posting through a use case. Every resolution is audit-logged.

## 9. Tests this document requires

- §4 worked example as one end-to-end test with exact amounts.
- Property tests: random carts (1–5 vendors, 1–6 lines each), random discounts and points, then random sequences of partial refunds, cancellations, disputes and payouts. After every step, check:
  - all transactions balance;
  - INV-5 holds;
  - vendor payable + commission − promo − loyalty reconciles to captured minus refunded;
  - no balance bucket is negative except via receivable.
- Concurrency (MySQL):
  - refund vs maturity on the same VendorOrder;
  - two payout runs;
  - duplicated `RefundSucceeded`;
  - dispute opened while a refund is being requested.
