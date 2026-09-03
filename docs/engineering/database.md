# Database & Migration Conventions

Nexo runs on MySQL/MariaDB in development and production; the test suite runs on
in-memory SQLite. Write migrations and queries that work on both — avoid
vendor-specific SQL unless it is guarded and justified.

The marketplace schema is **not** designed yet. This document sets the rules
future migrations follow; it does not change existing tables.

## Migrations

- **Reversible where practical.** Write a real `down()` that undoes `up()`. If a
  change genuinely cannot be reversed (data loss), say so in the PR and in a
  comment on the migration.
- **One concern per migration.** Don't bundle an unrelated column add with a
  table rename.
- **Additive first.** Prefer add-column / backfill / switch-reads / drop-column
  across separate deploys over a single breaking change.
- Never edit a migration that has run in any shared environment — add a new one.

## Naming

Follow Laravel conventions:

- tables: plural snake_case (`order_groups`, `stock_movements`)
- pivots: singular, alphabetical (`order_product`)
- columns: snake_case; foreign keys `singular_id` (`order_group_id`)
- booleans read as a state (`is_active`, `approved`)
- timestamps end in `_at` (`approved_at`, `shipped_at`)

## Keys, indexes, constraints

- **Foreign keys are intentional.** Declare them with an explicit `on delete`
  behaviour (`restrict`/`cascade`/`set null`) chosen for that relationship —
  never by accident.
- **Index for real access patterns.** Add an index when a query filters, joins,
  or sorts on a column at scale — not "just in case". Composite index column
  order matches the query.
- Use a unique constraint to enforce a real uniqueness rule (e.g. one
  `order_number` per vendor) rather than checking in PHP.

## Money

- Store money as integer minor units (`*_cents`) plus an explicit currency
  column. **Never `float`/`double`/`real` for money.**
- Use `decimal` only for rates/percentages (e.g. `commission_rate`), with a
  fixed precision and scale.
- Amounts that must not drift over time (commission rate on an order) are frozen
  onto the row at creation — see `docs/architecture/INVARIANTS.md`.

## Timestamps

- Every domain table has `created_at` / `updated_at` unless there is a specific
  reason not to.
- Store UTC. Format for the user at the edge.

## Destructive changes

- Dropping or renaming a column/table, or changing a type in a lossy way,
  requires explicit call-out in the PR description and reviewer sign-off.
- Before a destructive migration, state what production data exists in that
  column and what happens to it. Back up / export first if the data has value.
- A data backfill that touches many rows is chunked and safe to re-run
  (idempotent).

## Seeders & factories

- Factories define a minimal valid model; states express variations.
- Seeders are for local/demo data only. Never seed environment-specific or real
  user data. Production reference data is inserted by a migration.
