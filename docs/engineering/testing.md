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

## Naming

Describe the behaviour, not the method under test.

```php
it('rejects checkout when a cart line exceeds available stock');
it('freezes the commission rate onto the order at creation');
```

Not `testCheckout` or `it('works')`. A failing test name should tell a reader
what guarantee broke.

## Database

- Feature tests use the in-memory SQLite connection configured in `phpunit.xml`.
  Nothing in a test may point at a real database.
- Build state with factories, not raw inserts or shared seeders.
- Assert against observable behaviour (a response, a returned value, a row that
  now exists) rather than internal implementation detail where practical.

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
php artisan test                     # full suite
php artisan test --filter=Checkout   # by name
composer test                        # clears config cache, then runs the suite
```
