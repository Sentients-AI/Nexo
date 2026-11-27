# Nexo

**Nexo** is a multi-vendor marketplace. Many independent vendors sell through
one storefront; a customer fills one cart across vendors and pays once. The
platform splits that payment into one order per vendor, keeps a commission, and
accounts for every sen it owes each vendor in a double-entry ledger.

The storefront is the easy part. Nexo is built around the parts that usually go
wrong: one payment split across vendors, a refund that touches exactly one
vendor's line, the last unit sold once under concurrent checkouts, and a vendor
team that can never see another vendor's data. Each of those is a written
decision ([`docs/architecture/adr/`](docs/architecture/adr/)), an invariant with
a named proof ([`INVARIANTS.md`](docs/architecture/INVARIANTS.md)), and a test
that runs on MySQL. The product itself is in
[`docs/product/product-requirements.md`](docs/product/product-requirements.md).

Prices are in MYR. The storefront and vendor dashboard ship in English, Malay
and Arabic, right to left included (ADR-0007).

> **Status:** the frontend foundation (M1–M5) and the platform baseline are
> merged: a modular monolith with enforced boundaries (ADR-0010), every database
> test on MySQL behind a test database guard with a process-based concurrency
> harness (ADR-0009), and Redis contracts proven on Valkey in CI (ADR-0022). The
> Vendors and Identity modules exist: vendor registration and lifecycle,
> memberships and ownership, and an append-only audit log written in the same
> transaction as the change (ADR-0011, ADR-0023, ADR-0024). Catalogue, checkout
> and payments are not built yet. In v1, payments run in Stripe test mode and
> vendor payouts are simulated (ADR-0016, ADR-0021).

## What exists today

| Milestone | What it added |
|---|---|
| M1 | Vue 3 + Inertia 3 + TypeScript (strict) shell; the Node CI job |
| M2 | Design tokens as plain CSS custom properties, mapped into Tailwind 4 |
| M3 | Locale URLs (`/`, `/ms`, `/ar`), en/ms/ar translations, RTL, `hreflang`, `Intl` formatting in MYT |
| M4 | Accessible component kit (`resources/js/shared/ui/`), axe-tested |
| M5 | Storefront, checkout and vendor layouts with a language switcher; translated error pages; security headers with a nonce-based CSP |

Every UI string ships in English, Malay and Arabic (Arabic is right to left).
Storefront and vendor code are kept apart (dependency rule F1).

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
| CI | GitHub Actions: `PHP 8.5 / Laravel 13` and `Node 24 / Vue 3` |

## Prerequisites

- PHP **8.5** with the standard Laravel extensions (`mbstring`, `pdo`,
  `pdo_mysql`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`,
  `bcmath`, `fileinfo`)
- [Composer](https://getcomposer.org/) 2
- MySQL 8.4 (8.0 tolerated locally), for the development database `nexo` and the test database `nexo_testing`
- Node.js **24** (`^24.15`, see `.nvmrc`) and npm. `engine-strict` is on, so
  `npm ci` fails on any other Node version.

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
environment, and against a cached configuration. See
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

## Running the quality gate

Run all of these before integrating a change — together they are exactly what
CI enforces. `main` accepts a commit only when **both** CI jobs passed on it.

```sh
composer check          # validate, audit, Pint, PHPStan, Pest
npm run typecheck       # vue-tsc
npm test                # Vitest: components, axe, translations, F1 and F4 rules
npm audit --omit=dev    # production npm dependencies
```

Individually:

```sh
composer lint          # Laravel Pint — format in place
composer test:lint     # Pint — check only
composer analyse       # PHPStan + Larastan
composer test          # Pest
```

Local development server:

```sh
composer dev           # server + queue + logs + vite
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
- [Code quality & tooling](docs/engineering/code-quality.md) — also covers design tokens, i18n, the component kit, layouts and error pages
- [Git workflow](docs/engineering/git-workflow.md)
- [Issue workflow](docs/engineering/issue-workflow.md)
- [Definition of Done](docs/engineering/definition-of-done.md)
- [Architecture guidelines](docs/engineering/architecture-guidelines.md)
- [Dependency rules](docs/architecture/dependency-rules.md)
- [Database conventions](docs/engineering/database.md)
- [Error handling & logging](docs/engineering/error-handling.md)
- [Security baseline](docs/engineering/security.md) — also covers security headers and the CSP

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
privately — see [`SECURITY.md`](SECURITY.md).

## License

MIT — see [`LICENSE`](LICENSE).
