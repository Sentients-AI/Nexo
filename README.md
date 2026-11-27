# Nexo

**Nexo** is a multi-vendor marketplace. Many independent vendors sell through
one storefront; a customer fills one cart across vendors and pays once. The
platform splits that payment into one order per vendor, keeps a commission, and
accounts for every sen it owes each vendor in a double-entry ledger.

The storefront is the easy part. Nexo is designed around the parts that usually go
wrong: one payment split across vendors, a refund that touches exactly one
vendor's line, the last unit sold once under concurrent checkouts, and a vendor
team that can never see another vendor's data. Each of those is a written
decision ([`docs/architecture/adr/`](docs/architecture/adr/)) and an invariant
that names its enforcement and its proof
([`INVARIANTS.md`](docs/architecture/INVARIANTS.md)). What is built is proven by
tests on MySQL, including tests that race real processes against each other. The product itself is in
[`docs/product/product-requirements.md`](docs/product/product-requirements.md).

Prices are in MYR. The storefront and vendor dashboard ship in English, Malay
and Arabic, right to left included (ADR-0007).

> **Status:** the frontend foundation, the platform baseline and the vendor
> core (Vendors, Identity, audit) are on `main`. Customer-facing commerce is
> next. Details below.

## What exists today

| Area | What it added |
|---|---|
| M1 | Vue 3 + Inertia 3 + TypeScript (strict) shell; the Node CI job |
| M2 | Design tokens as plain CSS custom properties, mapped into Tailwind 4 |
| M3 | Locale URLs (`/`, `/ms`, `/ar`), en/ms/ar translations, RTL, `hreflang`, `Intl` formatting in MYT |
| M4 | Accessible component kit (`resources/js/shared/ui/`), axe-tested |
| M5 | Storefront, checkout and vendor layouts with a language switcher; translated error pages; security headers with a nonce-based CSP |
| M6 | MySQL 8.4 for every database test, behind a test database guard, with a process-based concurrency harness (ADR-0009); Redis contracts proven on an ACL-restricted Valkey in CI (ADR-0022); modules under `app/Modules/` with dependency rules enforced by `tests/Arch` (ADR-0010) |
| Vendors | The `vendors` table and lifecycle (`pending` → `approved` ⇄ `suspended`), audited registration and lifecycle changes, memberships with one active owner per vendor, and a documented lock order for every ownership change (ADR-0005, ADR-0011, ADR-0024) |
| Identity | `platform_role` on users and the `Users` contract other modules call (ADR-0011) |
| Audit | Append-only `audit_log`, written in the same transaction as the change it records; one writer, enforced statically (ADR-0023, INV-19) |

Every UI string ships in English, Malay and Arabic (Arabic is right to left).
Storefront and vendor code are kept apart (dependency rule F1).

Not built yet: authentication and the vendor dashboard behind it, catalogue,
cart, checkout, payments, the ledger and the admin panel.

## Limitations in v1

Each of these is deliberate and recorded in an ADR or the PRD. Lifting one
needs a new decision. The payment and payout rules are designed but not built
yet.

- Payments run in Stripe test mode only, and live keys are refused at boot
  (ADR-0021, proposed).
- Vendor payouts are calculated and posted to the ledger, then paid out as
  simulated transfers, with no Stripe Connect (ADR-0016; core approved as D-6).
- One currency, MYR. Prices are tax-inclusive, and Nexo does not calculate,
  itemise or remit tax (ADR-0007).
- Placing an order needs a signed-in account with a verified email; there is
  no guest checkout (PRD P-1).
- A user belongs to at most one vendor (ADR-0011).
- The platform admin is English-only; the storefront and vendor dashboard ship
  in all three languages (ADR-0020).

## Stack

| | |
|--|--|
| Language  | PHP 8.5, TypeScript (strict) |
| Framework | Laravel 13, Inertia 3, Vue 3 |
| Styling | Tailwind CSS 4 over design tokens (`resources/css/tokens.css`) |
| Build | Vite 8 |
| Database  | MySQL 8.4 everywhere, including tests (ADR-0009); MySQL 8.0 tolerated locally |
| Tests     | Pest 5 (PHP), Vitest + Vue Test Utils + axe-core (frontend) |
| Formatting | Laravel Pint |
| Static analysis | PHPStan + Larastan (level 6), `vue-tsc` |
| Redis contracts | Valkey 8.1 with phpredis, ACL-restricted (ADR-0022) |
| CI | GitHub Actions: `PHP 8.5 / Laravel 13` and `Node 24 / Vue 3` |

## Prerequisites

- PHP **8.5** with the standard Laravel extensions (`mbstring`, `pdo`,
  `pdo_mysql`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`,
  `bcmath`, `fileinfo`)
- [Composer](https://getcomposer.org/) 2
- MySQL 8.4 (8.0 tolerated locally), for the development database `nexo` and the test database `nexo_testing`
- Node.js **24** (`^24.15`, see `.nvmrc`) and npm. `engine-strict` is on, so
  `npm ci` fails on any other Node version.
- Optional: Docker and the phpredis extension, to run the Redis suite locally
  against Valkey 8.1. Without them its tests are skipped; CI always runs them
  ([`docs/engineering/testing.md`](docs/engineering/testing.md#redis-tests--testsredis)).

## Setup

```sh
git clone https://github.com/Sentients-AI/Nexo.git
cd Nexo

composer install

cp .env.example .env
php artisan key:generate
```

### Database

Create the database referenced by `.env` (`nexo` by default), set
`DB_USERNAME` / `DB_PASSWORD` to your local credentials, then:

```sh
php artisan migrate
```

The test suite never uses this database. It runs against `nexo_testing`
(see `phpunit.xml`), which you create once:

```sh
mysql -uroot -h127.0.0.1 -e "CREATE DATABASE nexo_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
```

A guard refuses to run tests against any other database, host or
environment, and against a cached configuration. Locally the tests may connect
as `root`; CI connects as `nexo_test`, whose only privileges are on
`nexo\_testing%` (`.github/mysql/create-test-user.sql`). See
[`docs/engineering/testing.md`](docs/engineering/testing.md#database).

### Front-end assets

```sh
npm ci
npm run build      # or: npm run dev
```

The PHP feature tests render `@vite`, so run `npm run build` once before
`composer test`.

### One-shot

```sh
composer setup     # install, .env, key:generate, migrate, npm install, npm build
```

`composer setup` migrates, so create the `nexo` database first.

## Running the quality gate

Run these in this order before integrating a change
([`definition-of-done.md`](docs/engineering/definition-of-done.md)). They are
what CI runs, except that CI also checks the package policy and always runs
the Redis suite. `main` accepts a commit only when **both** CI jobs passed on it.

```sh
npm ci                  # the feature tests render @vite, so build first
npm run build
composer check          # validate, audit, Pint, PHPStan, every Pest suite
npm run typecheck       # vue-tsc
npm test                # Vitest: components, axe, translations, F1 and F4 rules
npm audit --omit=dev    # production npm dependencies
```

Individually:

```sh
composer lint          # Pint: format in place
composer test:lint     # Pint: check only
composer analyse       # PHPStan + Larastan
composer test          # Pest: Arch, Unit+Feature, Platform+Concurrency, Redis (each in its own process)
```

The Redis suite runs only with `NEXO_TEST_REDIS=1`; without it, its tests are
reported as skipped.

Local development server:

```sh
composer dev           # server + queue + vite
# or
php artisan serve
```

### Local-only pages

With `APP_ENV=local`, a development bar at the top of every page links to:

| Page | URL |
|---|---|
| Design tokens | `/dev/tokens` |
| Component gallery (en / ms / ar) | `/dev/components` |
| Checkout layout preview | `/dev/layouts/checkout` (also `/ms/…`, `/ar/…`) |
| Vendor layout preview | `/dev/layouts/vendor` (also `/ms/…`, `/ar/…`) |

These routes are not registered in any other environment and return 404 there.

### Operator commands

Until the admin panel exists, vendors are managed from the console. Each
command goes through an audited use case and asks for confirmation in
production (`--force` skips it).

| Command | What it does |
|---|---|
| `php artisan vendors:create {slug} {name}` | Registers a `pending` vendor. It cannot operate until approved. |
| `php artisan vendors:assign-first-owner {vendor} {user}` | Gives an unowned vendor its first owner |
| `php artisan vendors:reassign-owner {vendor} {owner} {replacement}` | Moves ownership to another active member |

The audit log records these as console actions: it names the command, not the
person who ran it.

## Documentation

| | |
|--|--|
| Product requirements, journeys, scope | [`docs/product/`](docs/product/) |
| Screens per journey, and what each waits for | [`docs/product/screen-inventory.md`](docs/product/screen-inventory.md) |
| Architecture, invariants, ADRs | [`docs/architecture/`](docs/architecture/) |
| Engineering standards & process | [`docs/engineering/`](docs/engineering/) |
| Local development guidance | [`docs/development/`](docs/development/) |
| Full documentation index | [`docs/README.md`](docs/README.md) |

Key engineering docs:

- [Testing conventions](docs/engineering/testing.md)
- [Code quality & tooling](docs/engineering/code-quality.md): also covers design tokens, i18n, the component kit, layouts and error pages
- [Git workflow](docs/engineering/git-workflow.md)
- [Issue workflow](docs/engineering/issue-workflow.md)
- [Definition of Done](docs/engineering/definition-of-done.md)
- [Architecture guidelines](docs/engineering/architecture-guidelines.md)
- [Dependency rules](docs/architecture/dependency-rules.md)
- [Database conventions](docs/engineering/database.md)
- [Error handling & logging](docs/engineering/error-handling.md)
- [Security baseline](docs/engineering/security.md): also covers security headers and the CSP
- [Rate limiting](docs/engineering/rate-limiting.md)
- [Invariants](docs/architecture/INVARIANTS.md) and the [ADR index](docs/architecture/README.md#architecture-decision-records)

## Contributing

Nexo has one maintainer and a short-lived-branch workflow; pull requests are
optional. `main` is protected with no bypass: it accepts only a fast-forward to
a commit that passed both required CI checks, made with the owner's explicit
authorisation. See [`CONTRIBUTING.md`](CONTRIBUTING.md) and
[`docs/engineering/git-workflow.md`](docs/engineering/git-workflow.md).

## Domain naming note

The product calls a seller a **vendor**. [ADR-0005](docs/architecture/adr/0005-vendor-naming.md)
(accepted) uses `Vendor` for the marketplace domain, persisted in the Vendors
module's `vendors` table, and code and docs follow it. It supersedes
[ADR-0002](docs/architecture/adr/0002-vendor-is-a-tenant-record.md), which
borrowed `tenant` from Laravel's multi-tenancy tooling.

## Security

This repository is public. Never commit secrets. Report vulnerabilities
privately; see [`SECURITY.md`](SECURITY.md).

## License

MIT. See [`LICENSE`](LICENSE).
