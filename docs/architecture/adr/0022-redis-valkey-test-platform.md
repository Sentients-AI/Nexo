# ADR-0022: Prove the Redis contracts on a standalone, ACL-restricted Valkey in CI

- **Number:** 0022
- **Status:** Accepted, by owner decision (2026-11-17): Valkey 8.1; number 0022.
- **Date:** 2026-11-17
- **Decision owner:** Project owner
- **Supersedes / relates to:** completes the Redis test rules deferred by ADR-0009 (status line, §5); relates to ADR-0010 (package adoption deferred), ADR-0021

---

## Context

Verified on 2026-11-17 (`main` = `a4b61f1`):

- ADR-0009 §5: Redis carries queue, cache and locks in deployed environments. Its status line defers "Redis test rules" to a later ADR. No such ADR exists.
- `system-overview.md` §2 also lists rate limiting and scheduler mutexes on Redis.
- `config/database.php`: `redis.client` defaults to `phpredis`; `options.prefix` is derived from `APP_NAME`; `default` and `cache` connections use DB 0 and DB 1.
- `phpunit.xml`: `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`. No test touches Redis.
- CI (`.github/workflows/ci.yml`): MySQL 8.4 service only; PHP extensions `mbstring, pdo, pdo_mysql`. No Redis service and no redis extension.
- Local: PHP 8.5.8 NTS x64 (Windows/Laragon), with no `redis` extension loaded. `predis/predis` is not required.
- ADR-0010 was accepted *without* the package-adoption text ("first-party packages are deferred"). No accepted rule governs package presence or absence.
- Assumption, to be verified at implementation: a phpredis release that builds and loads on PHP 8.5 is installable through `shivammathur/setup-php`.

## Problem

How do we prove that Laravel's Redis-backed cache, locks, rate limiter and queue behave correctly, without letting a test reach, read or erase data it does not own?

## Decision drivers

- Behave like the deployed engine (protocol and Lua semantics), as ADR-0009 did for MySQL.
- Fail closed: a wrong host, identity or namespace stops the run before any command executes.
- Destructive commands are impossible, not just avoided.
- CI proofs cannot quietly turn into skips (the M6-D-03 pattern).
- No production infrastructure and no new runtime packages.

## Considered alternatives

### A. Keep `database`/`array` drivers in all tests; trust Redis in production

Rejected. Lock, queue and rate-limiter semantics on Redis (Lua scripts, TTL precision, `SET NX PX`) would go unproven, the gap Phase 0 found with SQLite.

### B. Redis OSS image in CI

Viable, since the protocol is identical. Rejected as the default because Redis ≥ 8 is AGPL/RSAL-licensed while Valkey is BSD-3, and Valkey is the likely managed-service target. The contract suite is engine-neutral, so switching later costs only a different image tag.

### C. Standalone Valkey, ACL-restricted, CI-mandatory, locally optional (chosen)

### D. Valkey/Redis Cluster in CI

Rejected for now: no deployment topology is decided. Deferred with a named trigger (Decision §9).

## Decision

1. **Scope.** Test infrastructure only: one standalone Valkey in CI, a `redis` test connection, guards, an ACL, contract tests, a command inventory and a package-absence check. No production Redis config, no Cluster, no Sentinel, no TLS, no session-on-Redis.

2. **CI engine.** The official Debian-based (`bookworm`) Valkey image, Valkey 8.1, pinned to an exact patch tag and digest recorded in the workflow.
   - GitHub service containers cannot take a command or mount repository files, so Valkey starts in a workflow step after checkout: `docker run -d -p 127.0.0.1:6379:6379 -v $PWD/.github/valkey:/etc/valkey:ro … valkey-server /etc/valkey/valkey.conf`, followed by a `PING` health loop.
   - It is reached only at `127.0.0.1`, as C-12 requires for MySQL.
   - Persistence is off (`save ""`, `appendonly no`).

3. **Client.** phpredis, through `setup-php` `extensions: redis-<x.y.z>`, pinned to an exact release. CI evidence step: `php --ri redis`. A release counts as verified only once CI shows it loading on PHP 8.5 and the contract suite passes. `predis/predis` is not added.

4. **ACL identity and namespace.** Defined in `.github/valkey/users.acl`:
   - `default` is `off`.
   - `nexo_ci_admin`: CI-step-only (seeding the sentinel, `INFO commandstats`). Its password never reaches the PHP test environment.
   - `nexo_test`: `resetkeys ~nexo_testing:* resetchannels &nexo_testing:* -@all +<inventory commands>`.
   - Namespace: `REDIS_PREFIX=nexo_testing:`, and `nexo_testing:test_<N>:` under `--parallel`.
   - DB 0 only: the `cache` Redis connection is pointed at DB 0 in tests, and `SELECT` is denied.

5. **Guard (extends the ADR-0009 §3a pure guard, same call sites).** For every Redis connection the tests configure (`default`, `cache`, and any other, checked generically):
   - R1: host in `127.0.0.1`, `localhost`, `::1`.
   - R2: empty `url`, no `scheme`/TLS, no `cluster`/`clusters` configuration.
   - R3: `username` is exactly `nexo_test`.
   - R4: the effective prefix matches `^nexo_testing:(test_[1-9][0-9]*:)?$`.
   - R5: database `0`.
   - R6, a live check once per process before the first test: `ACL WHOAMI` = `nexo_test`, and `FLUSHDB` returns `NOPERM`. If `FLUSHDB` is permitted, the ACL is not in force and the run aborts.

6. **Prohibited commands.** They are absent from the allowlist, and a test asserts `NOPERM` for each one:
   - `FLUSHALL`, `FLUSHDB`, `SWAPDB`, `SELECT`, `MOVE`, `MIGRATE`, `RENAME*` across namespaces (`RENAME` stays denied unless the inventory needs it);
   - `KEYS`, `SCAN`, `RANDOMKEY`;
   - `CONFIG`, `DEBUG`, `SHUTDOWN`, `SAVE`, `BGSAVE`, `BGREWRITEAOF`, `REPLICAOF`, `SLAVEOF`, `FAILOVER`, `CLUSTER`, `MODULE`, `ACL`, `MONITOR`, `CLIENT KILL`, `CLIENT PAUSE`;
   - `SCRIPT FLUSH`, `FUNCTION FLUSH`, `FUNCTION DELETE`, `FUNCTION LOAD`.

   Consequence: `Cache::flush()` on the Redis store, which issues `FLUSHDB`, is unusable in tests by design. Clean-up deletes only keys the test recorded, with `UNLINK`.

7. **Contract tests.** A new `Redis` testsuite (`tests/Redis`):
   - **Cache:** `put`/`get`/`add`/`increment`/`forever`/`forget`/`many`, TTL expiry, and atomicity of `add`.
   - **Locks:** `Cache::lock` across two independent PHP processes using the existing concurrency harness. Exactly one acquires; owner-only release; expiry.
   - **Rate limiter:** `hit`, `tooManyAttempts`, `availableIn`, `clear`.
   - **Queue:** push, pop, delay, release, delete, `retry_after` re-reservation, and failure through a real `queue:work --once` child process.
   - **Isolation:** `DEL`/`SET` on a key outside `nexo_testing:` returns `NOPERM`. A sentinel key seeded by `nexo_ci_admin` before the suite is verified unchanged after it.
   - **Fail-closed negative controls:** a child process with a wrong host, a wrong username, a wrong password and a wrong prefix each exit before any command (asserted by the guard reason, and by `INFO commandstats` showing no new calls from that child).

8. **Command inventory and drift.** `tests/Redis/command-inventory.php` lists every command the suite needs. It is the single source:
   - `users.acl` is generated from it, and a test asserts that the live `ACL GETUSER nexo_test` matches it;
   - after the suite, a CI step reads `INFO commandstats` as `nexo_ci_admin` and fails if any command ran that is not in the inventory, or if any inventory command never ran (a stale entry).

   Commands executed inside Lua scripts are counted, so the queue and lock scripts are covered.

9. **Deferred Cluster verification.** Not built now. Trigger: an ADR choosing a clustered or managed-sharded Redis/Valkey for a deployed environment. Then add a Cluster CI job that verifies the queue keys share a hash slot (`{queues:<name>}` tagging) and that the lock and rate-limiter keys are single-key.

10. **Package-absence policy.** A CI-enforced test reads `composer.lock` and fails if any listed package is present. Initial list: `predis/predis`, `adilazhari/laravel-trace`, `adilazhari/laravel-idempotency` (the names PR #19 already excludes). This rule is scoped to this ADR's list only; it does not decide ADR-0010's deferred adoption policy.

11. **Local and CI behaviour.**
    - The `Redis` suite runs only when `NEXO_TEST_REDIS=1`.
    - Locally, without it, or without the `redis` extension, each test is skipped with an explicit reason.
    - In CI (`ContinuousIntegration::detected()`) the same conditions fail the run.
    - The existing suites keep `CACHE_STORE=database` and `mysql_locks`. Nothing in Unit, Feature, Platform or Concurrency changes.

## Positive consequences

- Redis-backed behaviour is evidence-based before any feature depends on it.
- Data loss is prevented by the server (ACL), not only by test discipline.
- Inventory drift is visible both ways.

## Negative consequences

- About 15 s more CI time, plus a container and a second identity to maintain.
- `Cache::flush()` and `SCAN`-based helpers are unavailable in Redis tests.
- Contributors cannot run the Redis suite on Windows unless a PHP 8.5 phpredis DLL is available, so local runs are optional.

## Risks

- phpredis may lag PHP 8.5. Noticed at the first CI run (`php --ri redis`). Phase B blocks; there is no fallback to predis without a new decision.
- ACL rules diverge from the inventory. Caught by the `ACL GETUSER` assertion.
- A future Laravel upgrade changes its Lua scripts. Caught by the commandstats drift check.

## Testing implications

- New: `tests/Redis/*`, guard unit tests for R1–R5 (pure), R6 live proof, negative-control child processes, the inventory drift step and the package-absence test.
- CI gains: a Valkey start step, `redis` in the setup-php extensions, a `php --ri redis` evidence step, `NEXO_TEST_REDIS=1`, a run of `--testsuite=Redis` (sequential), and the commandstats step.

## Migration implications

- New files: `.github/valkey/valkey.conf`, `.github/valkey/users.acl`, `tests/Redis/`.
- Changed files: `phpunit.xml` (testsuite), `ci.yml`, the guard class, `docs/engineering/testing.md`, the ADR index.
- No production config change and no data migration.

## Related

- ADR-0009 (§3a guard, §5 Redis), ADR-0010 (package adoption deferred), ADR-0021 (environments), M6-D-03 (CI-mandatory proofs).
