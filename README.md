# Nexo

**Nexo** is the commerce engine powering **aljebal-albeedos (الجبل الأبيض)** — a
single Arabic-first online marketplace where many independent vendors sell
through one storefront. Customers shop across vendors in one cart and pay once;
the platform splits each purchase into per-vendor orders, retains a commission,
and settles vendors.

Nexo is infrastructure. There is exactly one marketplace running on it. See
[`docs/product/vision.md`](docs/product/vision.md) for the full product framing
and [`docs/architecture/marketplace-migration.md`](docs/architecture/marketplace-migration.md)
for where the codebase is headed.

> **Status:** Phase 3 frontend foundation (milestones M1–M5) is merged. The
> marketplace business capabilities (accounts, vendor onboarding, catalogue,
> split checkout, payments) are not built yet. Every database test runs on
> MySQL behind a test database guard, with a process-based concurrency
> harness (ADR-0009). Identity, typed page contracts, vendor context and the
> admin panel wait on ADR-0010, ADR-0011 and ADR-0018.

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

Run all of these before opening a pull request — together they are exactly what
CI enforces. A pull request merges only when **both** CI jobs are green.

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
| Product vision, scope, requirements | [`docs/product/`](docs/product/) |
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

Nexo uses an issue-driven, short-lived-branch, pull-request workflow. `main` is
protected: PRs require a passing CI run and one approval, and are squash-merged.
See [`CONTRIBUTING.md`](CONTRIBUTING.md) and
[`docs/engineering/git-workflow.md`](docs/engineering/git-workflow.md).

## Domain naming note

The product calls a seller a **vendor**. [ADR-0002](docs/architecture/adr/0002-vendor-is-a-tenant-record.md)
used `tenant` for the previous SaaS codebase; the proposed
[ADR-0005](docs/architecture/adr/ADR-0005-vendor-naming.md) uses `Vendor` for the
marketplace domain, and new code and docs follow it. No tenant or vendor model
exists in the code yet.

## Security

This repository is public. Never commit secrets. Report vulnerabilities
privately — see [`SECURITY.md`](SECURITY.md).

## License

MIT — see [`LICENSE`](LICENSE).
