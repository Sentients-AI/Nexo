# ADR-0009: Run Laravel 13 on PHP 8.5 with MySQL 8.4 in every environment, including tests

- **Number:** 0009
- **Status:** Accepted, by owner decisions M6-D-02 and M6-D-03 (2026-11-14), as amended. Redis test rules are deferred to a later ADR (MySQL first).
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
   - Unit tests use no database and the `array` cache.
   - Feature tests use the MySQL database `nexo_testing` with `RefreshDatabase`
     (one transaction per test, rolled back).
   - Under `--parallel`, Laravel creates one database per process named
     `nexo_testing_test_<N>`, where `<N>` is paratest's integer `TEST_TOKEN`.
   - Feature and concurrency tests use the `database` cache store. Locks use a
     dedicated connection `mysql_locks` (same database, separate PDO), so a lock is
     never inside a test or business transaction and is visible to other processes
     as soon as it is taken, matching Redis in deployed environments. Under
     `--parallel`, a test-only `ParallelTesting::setUpTestCase` callback points
     `mysql_locks` at the process database, because Laravel renames only the
     default connection. `cache_locks` is emptied after each test.
   - Concurrency tests (`tests/Concurrency`) run real parallel workers: separate PHP
     processes, each with its own connections, released together by a start barrier.
     Fixtures are committed before the workers start (`DatabaseTruncation`, not
     `RefreshDatabase`); workers receive the runtime database name explicitly; the
     suite asserts on worker results and on committed state, then truncates.
3a. **Test database guard.** The run aborts before any schema is created, dropped or
    migrated unless all of the following hold. One pure guard implementation is the
    only source of these rules. It runs in `tests/bootstrap.php`, in
    `Tests\CreatesApplication::createApplication()` (used by every test case, by the
    parallel runner's per-process set-up and by every concurrency worker), and again
    after the parallel database switch:
    - G1 `bootstrap/cache/config.php` (or the file named by `APP_CONFIG_CACHE`) does
      not exist;
    - G2 `APP_ENV` is `testing`;
    - G3 every database connection used by tests (currently `mysql` and
      `mysql_locks`, checked generically, including `read`/`write`
      sub-configurations) has a host in the allowlist `127.0.0.1`, `localhost`, `::1`;
    - G4 those connections have an empty `url` and `unix_socket`;
    - G5 the default connection's driver is `mysql`, and every database name matches
      `^nexo_testing(_test_[1-9][0-9]*)?$`.
    In CI the tests connect as a dedicated user whose only privileges are on
    `nexo\_testing%`; a dedicated local user is recommended, not enforced.
4. **CI** runs a MySQL 8.4 service container.
5. **Redis** is used for queue, cache and locks in deployed environments (demo). Local development may use the `database` queue and cache drivers.
6. **Local configuration caching is forbidden in development and tests.**
   `bootstrap/cache/config.php` must not exist in a working copy; guard G1 refuses
   to run tests if it does. This prevents the Phase 0 incident where a cached config
   pointed tests at the MySQL database `nile`.

## Positive consequences

- Tests prove behaviour on the production engine.
- Concurrency claims become evidence-based.

## Negative consequences

- Slower feature tests than in-memory SQLite. Mitigated by parallel testing (`--parallel` with per-process schemas) and by keeping logic in unit tests.
- Contributors need MySQL locally. Laragon already provides MySQL 8.0, so 8.4 is recommended but 8.0 is tolerated locally; CI pins 8.4.

## Risks

- Tests pointed at a real database. Mitigated by the host allowlist, the database
  name pattern, empty URL and socket, refusal to run with a cached config, and a
  CI user with privileges only on `nexo\_testing%`. Residual: a tunnel on a local
  address to a remote server; the name pattern and dedicated user cover it.
- Test-only lock leakage between tests (locks commit on `mysql_locks`). Mitigated by
  emptying `cache_locks` in tear-down.

## Testing implications

- `docs/engineering/testing.md` and `database.md` change from SQLite to MySQL in Phase 3. They are left untouched until this ADR is accepted.
- The concurrency harness is built in Phase 3 and used in Phases 4–5.
- A guard test proves G1–G5 reject: a cached config, `APP_ENV=local`, a non-allowlisted
  host, a non-empty URL, and the names `nexo`, `nexo_testing_x`, `prod_testing`,
  `nexo_testing_test_`, `nexo_testing_test_0`.
- A concurrency test proves two worker processes contending for one `Cache::lock`
  key get exactly one acquisition.

## Migration implications

- `phpunit.xml`, the CI workflow and `.env.example` change in Phase 3.
- No data migration is needed (fresh database).

## Related

- ADR-0014 (inventory), ADR-0015 (checkout), ADR-0021 (environments); Phase 0 §9, R4.
