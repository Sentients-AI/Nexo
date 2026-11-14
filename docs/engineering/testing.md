# Testing

Nexo uses [Pest](https://pestphp.com/) as its only test framework. Tests are a
merge gate: `php artisan test` must pass before a pull request is merged.

## Test types

### Feature tests — `tests/Feature/`

Feature tests exercise the application through a real boundary: an HTTP route, a
console command, a queued job, an event listener. They boot the framework and
run against a migrated database.

Use a feature test when the behaviour only makes sense end to end — routing,
middleware, validation, authorization, persistence, redirects, responses.

Feature tests automatically use `RefreshDatabase` (configured in
`tests/Pest.php`), so each test runs inside a transaction that is rolled back
afterwards. Do not truncate or seed global state manually.

### Unit tests — `tests/Unit/`

Unit tests cover isolated logic with no framework and no database: value
objects, money math, state-machine transitions, domain services that take their
dependencies as constructor arguments, pure functions.

Unit tests do **not** extend the Laravel `TestCase` and must not hit the
database, the filesystem, the network, the clock (use injected/faked time), or
the container. If a piece of logic needs any of those, either it belongs in a
feature test or the dependency should be injected so it can be faked.

### Architecture tests — `tests/Unit/ArchTest.php`

Pest's `arch()` assertions enforce structural rules (no debug helpers in
committed code, `env()` only read from `config/`, security/php presets). Add to
these when a new structural rule becomes worth enforcing automatically.

### Concurrency tests — `tests/Concurrency/`

Concurrency claims are proven with real parallel worker processes, never with
loops in one process (ADR-0009 §3). `Tests\Concurrency\Harness\Concurrently`
runs a `Scenario` in N separate PHP processes, each with its own application and
connections, released together by a start barrier:

```php
$results = Concurrently::run(app(), AcquireDatabaseLock::class, workers: 2, arguments: [...]);
```

- The suite uses `DatabaseTruncation` (`tests/Pest.php`): fixtures are
  committed before the workers start, so the workers can see them.
- Each worker boots through `Tests\CreatesApplication` and enforces the test
  database guard itself. It receives the parent's runtime database name.
- `prepare()` runs before the barrier (connect, resolve services); `run()` after.
  Use `WorkerContext::signal()` / `waitFor()` for a fixed order between
  workers, never sleeps.
- A run must finish within 30 s (`Concurrently::DEFAULT_TIMEOUT`); otherwise
  every worker is killed and the test fails with `ConcurrencyTimeout`.
- Assert on the workers' results **and** on the committed database state.
- Add a negative control where it is cheap (see the `array`-store control in
  `DatabaseLockContentionTest`), so the test is known to detect a failure.

### Platform proofs — `tests/Platform/`

Proofs about the test platform itself: the guard aborts before any schema
operation, lock-connection isolation, and the CI user's privileges. They create
only the probe databases listed in `Tests\Support\Database\ProbeDatabases`, so
they run sequentially and skip under `--parallel`.

## Naming

Describe the behaviour, not the method under test.

```php
it('rejects checkout when a cart line exceeds available stock');
it('freezes the commission rate onto the order at creation');
```

Not `testCheckout` or `it('works')`. A failing test name should tell a reader
what guarantee broke.

## Database

Every database test runs on MySQL (ADR-0009): CI pins MySQL 8.4; locally MySQL
8.0 (Laragon) is tolerated. Unit tests boot no application and use no database.

| | |
|---|---|
| Test database | `nexo_testing` (`phpunit.xml`) |
| Under `--parallel` | one per process, `nexo_testing_test_<N>`, created by Laravel |
| Cache store | `database`; locks on the `mysql_locks` connection |
| Feature tests | `RefreshDatabase`: one transaction per test, rolled back |
| Concurrency tests | `DatabaseTruncation`: committed fixtures, truncated after |

- Build state with factories, not raw inserts or shared seeders.
- Assert against observable behaviour (a response, a returned value, a row that
  now exists) rather than internal implementation detail where practical.
- Locks are taken on `mysql_locks`, a separate connection to the same database,
  so a lock is never inside the test transaction and other processes see it at
  once. `cache_locks` is emptied after every database test, after proving on the
  server that the lock connection is on the same test database.

### Test database guard

Tests refuse to run unless the configuration is safe (ADR-0009 §3a). One pure
implementation, `Tests\Support\Database\TestDatabaseGuard`, holds the rules:

| Rule | Requires |
|---|---|
| G1 | no cached configuration (`bootstrap/cache/config.php`, or `APP_CONFIG_CACHE`) |
| G2 | `APP_ENV=testing` |
| G3 | every test connection, including `read`/`write`, has host `127.0.0.1`, `localhost` or `::1` |
| G4 | those connections have an empty `url` and `unix_socket` |
| G5 | the default connection is `mysql`; every database matches `^nexo_testing(_test_[1-9][0-9]*)?$`; under `--parallel`, exactly `nexo_testing` or this worker's `nexo_testing_test_<token>` |

It runs in `tests/bootstrap.php` (aborting the whole run with a
`TEST-DB-GUARD Gn` message), in `Tests\CreatesApplication` as soon as
configuration loads and again after boot (every test case, Laravel's parallel
runner and every concurrency worker), and after the parallel database switch.
`tests/Platform/GuardAbortsBeforeSchemaTest.php` proves each rule stops a
migrating probe before it touches its database.

Consequences:

- Never run `php artisan config:cache` in a working copy. `composer test`
  clears the configuration cache before running.
- To test environment-gated behaviour, use
  `$this->rebootSimulatingEnvironment('local')`. Never change the process
  `APP_ENV` in a test: that would also load `.env.<environment>` files.
- Shell variables override `phpunit.xml` (it does not force values), so an
  exported `DB_DATABASE` or `DB_HOST` is caught by the guard rather than used.

### Local set-up

```sh
mysql -uroot -h127.0.0.1 -e "CREATE DATABASE nexo_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
```

Laragon's `root` user is fine locally. A dedicated user is recommended, not
enforced (M6-D-03). In CI the tests connect as `nexo_test`, whose only
privileges are on `nexo\_testing%` (`.github/mysql/create-test-user.sql`);
`tests/Platform/TestUserPrivilegesTest.php` proves what that user can and
cannot do, and runs only with `NEXO_TEST_PRIVILEGES=1`.

To reproduce CI's MySQL 8.4 and test user locally with Docker:

```sh
docker run -d --name nexo-mysql84 -e MYSQL_ROOT_PASSWORD=local-root-only \
  -e MYSQL_DATABASE=nexo_testing -p 127.0.0.1:33306:3306 mysql:8.4
mysql --protocol=TCP -h127.0.0.1 -P33306 -uroot -plocal-root-only < .github/mysql/create-test-user.sql
DB_PORT=33306 DB_USERNAME=nexo_test DB_PASSWORD=nexo_test NEXO_TEST_PRIVILEGES=1 php artisan test
docker rm -f nexo-mysql84
```

## External services

Tests never call a real external API, and never use production credentials.

- HTTP: `Http::fake()`.
- Mail / notifications / events / queue: the relevant Laravel fake.
- Payment providers, storage, search: fake or fake-double at the boundary. When
  the marketplace payment work lands, its provider client sits behind an
  interface with a fake implementation used in tests.

If a test would need the network to pass, the seam is in the wrong place.

## Mocking policy

Prefer real objects and Laravel's built-in fakes. Reach for Mockery only to
stand in for a boundary you own (an injected interface) — not to mock the class
under test, and not to assert on a long chain of internal calls. Over-mocked
tests pass while the system is broken.

## Determinism

A test must produce the same result on every run and in any order.

- No reliance on wall-clock time — freeze it (`travelTo()`), or inject a clock.
- No reliance on random values without a fixed seed.
- No dependence on test execution order or leftover state.

## Coverage

Write tests that protect a real guarantee: a requirement, an invariant
(`docs/architecture/INVARIANTS.md`), or a fixed bug. Do not add tests solely to
raise a coverage number — a test with no meaningful assertion is worse than no
test.

## Running tests

```sh
php artisan test                     # full suite, sequential
php artisan test --filter=Checkout   # by name
composer test                        # clears config cache, then runs the suite

# What CI runs:
php artisan test --parallel --processes=4 --testsuite=Unit,Feature
php artisan test --testsuite=Platform,Concurrency
```
