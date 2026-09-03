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

> **Status:** foundation. The marketplace business capabilities (vendor
> onboarding, catalog, split checkout, payments) are not built yet — see the
> migration plan for the sequenced phases.

## Stack

| | |
|--|--|
| Language  | PHP 8.5 |
| Framework | Laravel 13 |
| Database  | MySQL / MariaDB (dev & prod); SQLite in-memory (tests) |
| Tests     | Pest 5 |
| Formatting | Laravel Pint |
| Static analysis | PHPStan + Larastan (level 6) |
| CI | GitHub Actions |

## Prerequisites

- PHP **8.5** with the standard Laravel extensions (`mbstring`, `pdo`,
  `pdo_mysql` / `pdo_sqlite`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`,
  `bcmath`, `fileinfo`)
- [Composer](https://getcomposer.org/) 2
- MySQL or MariaDB (local marketplace database)
- Node.js 20+ and npm (front-end assets)

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

The test suite does **not** use this database — it runs against in-memory SQLite
(see `phpunit.xml`), so no test database setup is required.

### Front-end assets

```sh
npm install
npm run build      # or: npm run dev
```

### One-shot

```sh
composer setup     # install, .env, key:generate, migrate, npm install, npm build
```

## Running the quality gate

Run this before opening a pull request — it is exactly what CI enforces:

```sh
composer check
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

## Documentation

| | |
|--|--|
| Product vision, scope, requirements | [`docs/product/`](docs/product/) |
| Architecture, invariants, ADRs | [`docs/architecture/`](docs/architecture/) |
| Engineering standards & process | [`docs/engineering/`](docs/engineering/) |
| Local development guidance | [`docs/development/`](docs/development/) |
| Full documentation index | [`docs/README.md`](docs/README.md) |

Key engineering docs:

- [Testing conventions](docs/engineering/testing.md)
- [Code quality & tooling](docs/engineering/code-quality.md)
- [Git workflow](docs/engineering/git-workflow.md)
- [Issue workflow](docs/engineering/issue-workflow.md)
- [Definition of Done](docs/engineering/definition-of-done.md)
- [Architecture guidelines](docs/engineering/architecture-guidelines.md)
- [Database conventions](docs/engineering/database.md)
- [Error handling & logging](docs/engineering/error-handling.md)
- [Security baseline](docs/engineering/security.md)

## Contributing

Nexo uses an issue-driven, short-lived-branch, pull-request workflow. `main` is
protected: PRs require a passing CI run and one approval, and are squash-merged.
See [`CONTRIBUTING.md`](CONTRIBUTING.md) and
[`docs/engineering/git-workflow.md`](docs/engineering/git-workflow.md).

## Domain naming note

Per [ADR-0002](docs/architecture/adr/0002-vendor-is-a-tenant-record.md), the code
uses `tenant` / `Tenant` / `tenant_id` for what the product calls a **vendor**.
Same concept; a future ADR decides whether to rename.

## Security

This repository is public. Never commit secrets. Report vulnerabilities
privately — see [`SECURITY.md`](SECURITY.md).

## License

MIT — see [`LICENSE`](LICENSE).
