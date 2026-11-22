# Database & Migration Conventions

Nexo runs on MySQL 8.4 in every environment, including tests (ADR-0009). Local
development tolerates MySQL 8.0. Use InnoDB, strict mode and `utf8mb4`
(`utf8mb4_0900_ai_ci`). Code that relies on locking uses explicit
`SELECT … FOR UPDATE` or conditional `UPDATE … WHERE`, never read-then-write
without a lock; prove it with a concurrency test (`docs/engineering/testing.md`).

Tests run against `nexo_testing`, never the development database `nexo`.

## Collation in an existing development database

`.env.example` sets `DB_COLLATION=utf8mb4_0900_ai_ci`. Without that line,
`config/database.php` falls back to `utf8mb4_unicode_ci`, and development
databases created before it was added usually hold `unicode_ci` tables.

Adopting the setting changes only new connections and new tables. Existing
tables keep their collation, so a database can end up mixing both. Comparing or
joining string columns across the two then fails with *Illegal mix of
collations*. To check what a database holds:

```sql
SELECT table_collation, COUNT(*) FROM information_schema.tables
WHERE table_schema = 'nexo' GROUP BY table_collation;
```

To get to one collation, from least to most destructive:

1. **Use a fresh database** under a new name, created with
   `CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci`, point `DB_DATABASE` at
   it and migrate. The old database stays untouched.
2. **Convert in place** with
   `ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci`.
   Back the database up first (`mysqldump`), because this rewrites every table.
3. **Reset** with `php artisan migrate:fresh` only if the database is
   disposable. It drops every table. Run it on a database with data you want to
   keep only after a verified backup and the data owner's agreement.

Test databases (`nexo_testing`, `nexo_testing_test_<N>`) are disposable: the
test run rebuilds their tables with the collation phpunit.xml sets, and
`ServerBaselineTest` fails if a test database itself defaults to another
collation.

The marketplace schema is **not** designed yet. This document sets the rules
future migrations follow; it does not change existing tables.

## Migrations

- **Reversible where practical.** Write a real `down()` that undoes `up()`. If a
  change genuinely cannot be reversed (data loss), say so in the commit body and in a
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
  requires explicit call-out in the commit body and the owner's explicit
  approval before it is integrated.
- Before a destructive migration, state what production data exists in that
  column and what happens to it. Back up / export first if the data has value.
- A data backfill that touches many rows is chunked and safe to re-run
  (idempotent).

## Seeders & factories

- Factories define a minimal valid model; states express variations.
- Seeders are for local/demo data only. Never seed environment-specific or real
  user data. Production reference data is inserted by a migration.
