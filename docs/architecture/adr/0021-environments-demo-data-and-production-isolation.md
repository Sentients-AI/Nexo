# ADR-0021: Isolate every Nexo 2.0 environment from the legacy production system; reproduce the demo from a clean database

- **Number:** 0021
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

Phase 0 found that:

- the old staging deployed the `redesign` branch against the **production** database, with shared queue and cache tables;
- the production deploy ran `migrate --force` by default.

The brief (§3, §14) requires a fresh database, no connection to production, and a reproducible, isolated demo with believable data.

The old deploy pipelines are still active, because disabling them needs repository admin rights (D-12 follow-up).

## Problem

Which environments exist, how are they isolated, and how is the demo built?

## Decision drivers

- Zero shared credentials or data with the legacy system.
- Anyone can reproduce the demo.
- No accidental migrations against anything that matters.

## Considered alternatives

### A. Reuse the legacy server and database with a new schema

Rejected. It shares credentials and blast radius.

### B. Separate environments with a seeded demo (chosen)

## Decision

1. **Environments:**

   | Environment | Database | Payments | Purpose |
   |---|---|---|---|
   | local | `nexo` / `nexo_testing` on the developer's MySQL | Stripe test keys (optional) or the fake provider | Development |
   | CI | MySQL 8.4 service container | Fake provider only | Gates |
   | demo | Its own MySQL database and user on a host with **no** legacy credentials | Stripe **test mode** only | Public showcase |

   There is **no** production environment handling real customers or money in scope.

2. **Credentials:**
   - The demo `.env` is created fresh. No value is copied from the legacy `.env`.
   - Stripe live keys are rejected at boot: `sk_live_` and `pk_live_` prefixes throw in a service-provider check unless `NEXO_ALLOW_LIVE_PAYMENTS=true`, which no environment sets in v2.
3. **Migrations in deployed environments** run as an explicit, separate, manually approved workflow step. They never run as a side effect of a push.
4. **Demo data** comes from `php artisan nexo:demo:seed`, which is deterministic (fixed seed) and idempotent on an empty database. It creates:
   - 8–12 vendors with distinct, coherent product lines;
   - curated products with variants and licensed or royalty-free imagery (source and licence recorded in `docs/engineering/demo-environment.md`, Phase 3);
   - customers, orders in every status, multi-vendor checkouts, refunds, a dispute, promotions, reviews and notifications.

   Ledger postings are created **through the real use cases**, never by inserting balances. This means the demo data itself proves the ledger balances.
5. **Demo credentials** are published in the README for demo roles only (customer, vendor owner, vendor fulfilment, platform operations, platform finance). The demo can be reset nightly by a scheduled `migrate:fresh` + seed, guarded so it runs only when `APP_ENV=demo`.
6. **Legacy system:** the old repository's deploy workflows should be disabled by its admin (D-12). Nexo 2.0 workflows never reference legacy secrets.

## Positive consequences

- The brief's isolation rule is enforced by construction.
- The demo is reproducible from a clean database.

## Negative consequences

- Hosting for the demo must be provisioned (Phase 10).

## Risks

- **Copied secrets.** Mitigation: the live-key boot check, plus secret scanning in CI (Phase 3).

## Testing implications

- The seeder runs in CI on an empty database. Ledger invariants and the reconciliation checks pass on the seeded data.
- A boot test rejects live keys.

## Migration implications

None for v2. The legacy system is out of scope apart from containment (D-12).

## Related

- ADR-0009; Phase 0 §A.5, Addendum B.
