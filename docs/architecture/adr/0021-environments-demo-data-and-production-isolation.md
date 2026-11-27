# ADR-0021: Isolate every environment; reproduce the demo from a clean database

- **Number:** 0021
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

Two deployment habits cause most self-inflicted data loss in small teams:

- a staging environment that shares the production database, or its queue and cache tables;
- a deploy that runs `migrate --force` as a side effect of every push.

The brief (§3, §14) requires every environment to own its data, and a reproducible, isolated demo with believable data.

## Problem

Which environments exist, how are they isolated, and how is the demo built?

## Decision drivers

- Zero shared credentials or data between environments.
- Anyone can reproduce the demo.
- No accidental migrations against anything that matters.

## Considered alternatives

### A. One shared server and database, separated by schema or prefix

Rejected. It shares credentials and blast radius.

### B. Separate environments with a seeded demo (chosen)

## Decision

1. **Environments:**

   | Environment | Database | Payments | Purpose |
   |---|---|---|---|
   | local | `nexo` / `nexo_testing` on the developer's MySQL | Stripe test keys (optional) or the fake provider | Development |
   | CI | MySQL 8.4 service container | Fake provider only | Gates |
   | demo | Its own MySQL database and user on its own host, with no credentials shared with any other environment | Stripe **test mode** only | Public demo |

   A production environment handling real customers and money is out of scope for v1. It needs its own ADR before launch.

2. **Credentials:**
   - Each deployed `.env` is created fresh. No value is copied from another environment's `.env`.
   - Stripe live keys are rejected at boot: `sk_live_` and `pk_live_` prefixes throw in a service-provider check unless `NEXO_ALLOW_LIVE_PAYMENTS=true`, which no v1 environment sets.
3. **Migrations in deployed environments** run as an explicit, separate, manually approved workflow step. They never run as a side effect of a push.
4. **Demo data** comes from `php artisan nexo:demo:seed`, which is deterministic (fixed seed) and idempotent on an empty database. It creates:
   - 8–12 vendors with distinct, coherent product lines;
   - curated products with variants and licensed or royalty-free imagery (source and licence recorded in `docs/engineering/demo-environment.md`, Phase 3);
   - customers, orders in every status, multi-vendor checkouts, refunds, a dispute, promotions, reviews and notifications.

   Ledger postings are created **through the real use cases**, never by inserting balances. This means the demo data itself proves the ledger balances.
5. **Demo credentials** are published in the README for demo roles only (customer, vendor owner, vendor fulfilment, platform operations, platform finance). The demo can be reset nightly by a scheduled `migrate:fresh` + seed, guarded so it runs only when `APP_ENV=demo`.
6. **Secrets per environment:** CI workflows reference only the secrets of the environment they deploy to.

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

None (greenfield).

## Related

- ADR-0009.
