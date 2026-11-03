# ADR-0013: Represent money as integer minor units with an explicit currency and deterministic allocation

- **Number:** 0013
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

`docs/engineering/database.md` already says:

- money is integer minor units plus currency;
- never floats;
- decimals only for rates.

ADR-0007 fixes one currency (MYR). Several calculations split one amount across many parts:

- discounts across lines and vendors;
- loyalty points across lines;
- commission per line;
- dispute amounts across VendorOrders.

Each split must sum *exactly*, or the ledger cannot balance.

## Problem

What is the money type, how are rates represented, and how are rounding and allocation done?

## Decision drivers

- Exactness: the ledger always balances.
- Determinism: the same input always gives the same allocation.
- No floating point anywhere in money paths, including JavaScript.

## Considered alternatives

### A. `brick/money`

- Mature, with allocation support.
- Adds a dependency, and its `BigDecimal` contexts are more than a single-currency integer domain needs.
- A reasonable fallback if the first-party type proves insufficient.

### B. `moneyphp/money`

Similar trade-off to A.

### C. A small first-party immutable `Money` value object in `app/Shared/Money` (chosen)

About 150 lines with exhaustive unit tests.

## Decision

1. **`Money`** is `(int $minor, Currency $currency)`, immutable.
   - Operations: `add`, `subtract`, `multiplyByInt`, `isNegative`, `equals`, `allocate(array $weights)`, `percentage(BasisPoints)`.
   - Operations on mismatched currencies throw.
   - Overflow is checked; `PHP_INT_MAX` sen is far beyond any order.
2. **Rates** are integer **basis points** (`commission_rate_bps = 1000` means 10.00 %). No decimal columns are needed.
3. **Rounding:** `percentage()` uses **half-even** (banker's) rounding on the minor unit.
4. **Allocation** uses the **largest-remainder method**:
   - Compute floor shares proportional to the weights.
   - Distribute the leftover minor units one by one to the largest fractional remainders.
   - Ties go to the lowest line position.

   The result always sums to the input. Allocations are stored on the rows they apply to (line `discount_allocated_minor`, `points_value_allocated_minor`), so reversals use stored values and never recompute.
5. **Commission** is computed per line: `line_commission = percentage(line_goods_minor, rate_bps)`. The VendorOrder commission is the sum of its lines, so a partial refund of one line reverses exactly that line's commission.
6. **Columns:**
   - `*_minor BIGINT` (signed only where a negative value is meaningful, e.g. ledger entries are always positive with a direction column);
   - plus `currency CHAR(3)` on every table holding money.
7. **Serialisation to the frontend** is `{ amount: <int minor>, currency: "MYR" }`. Formatting happens client-side with `Intl.NumberFormat` (ADR-0018). The browser never does arithmetic that is persisted or trusted. Display totals come from the server.

## Positive consequences

- Exact sums everywhere.
- Reversals are trivially exact.
- One small, fully tested type.

## Negative consequences

- Owning a money type. Mitigated by its narrow scope and property tests.

## Risks

- Developers bypass `Money` with raw ints. Mitigation: arch test that `*_minor` attributes are cast to `Money` via an Eloquent cast, plus PHPStan.

## Testing implications

Property-based tests (random amounts and weights):

- the allocation sum is preserved;
- no share is negative;
- results are deterministic;
- `percentage` matches a reference implementation.

## Migration implications

None (greenfield).

## Related

- ADR-0016, `financial-model.md`.
