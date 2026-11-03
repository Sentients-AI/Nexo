# ADR-0009: Run Laravel 13 on PHP 8.5 with MySQL 8.4 in every environment, including tests

- **Number:** 0009
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner (open decision D-3)

---

## Context

- `main` already requires `php ^8.5` and `laravel/framework ^13.17`. Local PHP is 8.5.8.
- `.env.example` uses MySQL with database and cache-backed queue and cache. `phpunit.xml` and `docs/engineering/testing.md` pin tests to in-memory SQLite. CI installs only `pdo_sqlite`.
- Phase 0 showed the cost of SQLite tests. The old repository's "concurrency" and "torture" tests passed on SQLite with real double-subtraction and race bugs present, because:
  - `SELECT … FOR UPDATE` is a no-op on SQLite;
  - SQLite serialises all writers;
  - `CHECK` and type semantics differ from MySQL.
- The brief (§10, §11) requires tests for concurrent purchase, duplicate webhooks, simultaneous transitions and rollback behaviour.

## Problem

Which runtime versions and which database engine do code and tests run on?

## Decision drivers

- Tests must exercise the real locking, isolation and constraint semantics.
- Reproducibility for reviewers: one command, no surprises.
- CI time stays reasonable.

## Considered alternatives

### A. Keep SQLite for feature tests; add a separate MySQL suite for concurrency only

- Faster local feedback.
- But it keeps two behaviours. Constraint and `CHECK`, JSON, collation and locking differences leak into the "fast" suite and give false confidence, as happened in the old repository.

### B. MySQL 8.4 LTS for every test that touches the database (chosen)

- Unit tests stay database-free.
- Feature, integration and concurrency tests use a dedicated `nexo_testing` schema.

### C. PostgreSQL

- Excellent constraints, and partial indexes would help several designs.
- Rejected: the brief's baseline is MySQL, and the target host and demo hosting are MySQL-oriented.

## Decision

1. **PHP 8.5, Laravel 13.** Upgrade within minor versions freely. A major upgrade needs an ADR.
2. **MySQL 8.4 LTS** is the only supported database.
   - The engine is InnoDB.
   - The default isolation level is `REPEATABLE READ`. Code that relies on locks uses explicit `SELECT … FOR UPDATE` or conditional `UPDATE … WHERE` statements, never read-then-write without a lock.
   - The connection uses strict mode and `utf8mb4`/`utf8mb4_0900_ai_ci`.
3. **Tests:**
   - Unit tests use no database.
   - Feature tests use MySQL `nexo_testing`, with transactions rolled back per test via `RefreshDatabase`.
   - Concurrency tests run real parallel workers: separate PHP processes, each with its own connection, synchronised by a start barrier. They run against committed data with explicit cleanup, because a rollback-per-test transaction would hide concurrency.
   - `phpunit.xml` guards against any database whose name does not end in `_testing`. A bootstrap check aborts the run otherwise.
4. **CI** runs a MySQL 8.4 service container.
5. **Redis** is used for queue, cache and locks in deployed environments (demo). Local development may use the `database` queue and cache drivers.
6. **Local configuration caching is forbidden in development.**
   - `bootstrap/cache/config.php` must not exist in a working copy.
   - The test bootstrap refuses to run if it does.
   - This prevents the Phase 0 incident where a cached config pointed tests at the MySQL database `nile`.

## Positive consequences

- Tests prove behaviour on the production engine.
- Concurrency claims become evidence-based.

## Negative consequences

- Slower feature tests than in-memory SQLite. Mitigated by parallel testing (`--parallel` with per-process schemas) and by keeping logic in unit tests.
- Contributors need MySQL locally. Laragon already provides MySQL 8.0, so 8.4 is recommended but 8.0 is tolerated locally; CI pins 8.4.

## Risks

- Tests could be accidentally pointed at a real database. This is mitigated by the `_testing` name guard and by refusing to run with a cached config.

## Testing implications

- `docs/engineering/testing.md` and `database.md` change from SQLite to MySQL in Phase 3. They are left untouched until this ADR is accepted.
- The concurrency harness is built in Phase 3 and used in Phases 4–5.

## Migration implications

- `phpunit.xml`, the CI workflow and `.env.example` change in Phase 3.
- No data migration is needed (fresh database).

## Related

- ADR-0014 (inventory), ADR-0015 (checkout), ADR-0021 (environments); Phase 0 §9, R4.
