# Code Quality & Tooling

Three tools enforce the baseline. All three run in CI on every pull request, and
all three are runnable locally with the same result.

| Concern           | Tool                     | Command               |
|-------------------|--------------------------|-----------------------|
| Formatting        | Laravel Pint             | `composer lint`       |
| Static analysis   | PHPStan + Larastan       | `composer analyse`    |
| Tests             | Pest                     | `composer test`       |
| All gates at once | —                        | `composer check`      |

## Formatting — Laravel Pint

Pint is the only formatter. Do not add a second one (PHP CS Fixer, ECS, etc.).

```sh
composer lint          # fix formatting in place
composer test:lint     # check only — fails if anything is unformatted (CI mode)
```

There is no committed `pint.json`; the project uses Pint's default `laravel`
preset. Add a `pint.json` only with a documented reason to diverge.

Run `composer lint` before committing. If CI fails on style, run it and commit
the result — never hand-format to match.

## Static analysis — PHPStan + Larastan

Configuration lives in `phpstan.neon.dist` (committed). A developer may add an
untracked `phpstan.neon` locally to experiment, but the committed baseline is
the source of truth.

```sh
composer analyse
```

- **Level 6.** This gives full parameter/return type enforcement without
  fighting framework-shipped config defaults. Raise it one level at a time in a
  dedicated PR once the codebase clears the next level cleanly.
- **Analysed paths:** `app`, `bootstrap/app.php`, `config`, `database`,
  `routes`, `public/index.php`. Test files are not analysed by PHPStan — Pest's
  functional API is not statically resolvable without full Pest support; test
  structure is covered by `tests/Unit/ArchTest.php` and Pint instead.
- **Do not weaken the baseline to get green.** No broad `ignoreErrors`, no
  baseline file, no `@phpstan-ignore` sprinkled to silence a real problem. Fix
  the cause. A genuinely unavoidable ignore carries a comment explaining why.

## Styling — design tokens

- **Source of truth:** `resources/css/tokens.css`, plain CSS custom properties
  (`--nx-*`) with no Tailwind or Vue syntax, so the Filament admin can reuse
  them (ADR-0020). `resources/css/app.css` maps them into Tailwind's `@theme`.
- **Tailwind's defaults are cleared** for colour, radius, shadow, font, type
  scale and tracking. Utilities such as `bg-red-500` do not exist; use the
  semantic ones (`bg-surface`, `text-fg-muted`, `text-danger`, `rounded-md`).
- **Components use semantic tokens** (`--nx-color-*`), never `--nx-palette-*`.
- **Contrast is tested.** `resources/js/design/contrast-pairs.ts` derives the
  required pairs from token names (text 4.5:1, controls and focus 3:1,
  NFR-A11Y-03). A new colour token fails the suite until it is either covered
  by a pair or listed as decorative with a reason.
- **Gallery:** `/dev/tokens` renders every token. It is registered only when
  `APP_ENV=local` (`routes/local.php`) and 404s everywhere else.
- **Arabic** switches to IBM Plex Sans Arabic and taller line heights through
  `:lang(ar)`, so it also applies to Arabic content inside other locales.

## Internationalisation

- **Locales:** `en` (primary and fallback), `ms`, `ar` (RTL), per ADR-0007.
  The PHP source of truth is `App\Http\Localization\Locale`; the frontend
  mirror is `resources/js/i18n/locales.ts`.
- **URLs (NFR-I18N-04):** storefront routes are declared once inside
  `LocalizedRoutes::group()` in `routes/web.php` and registered for `/`,
  `/ms` and `/ar`, named `{locale}.{name}` (e.g. `ar.storefront.home`).
  `/en/...` redirects (301) to the unprefixed URL. Every localised page
  emits `hreflang` alternates plus `x-default`.
- **Strings:** `resources/js/i18n/messages/{locale}/{namespace}.json`; the
  key is `{namespace}.{path}` (e.g. `storefront.home.title`). Use
  `const { t } = useI18n()`; parameters are `{name}`, and plurals are objects
  keyed by `Intl.PluralRules` category (Arabic needs all six). Each page loads
  only its own locale plus English.
- **Coverage (NFR-I18N-01):** `resources/js/i18n/coverage.test.ts` fails CI
  when a locale is missing or has extra keys, a plural lacks a category the
  language uses, placeholders differ from English, or source calls `t()` with
  a key that does not exist. Every UI string ships in all three locales.
- **Formatting (NFR-I18N-03):** use `formatMoney`, `formatNumber`,
  `formatDate` and `formatDateTime` from `useI18n()`, never `toFixed` or
  hand-built strings. Dates are shown in `Asia/Kuala_Lumpur`; Arabic uses
  Latin digits; money is `RM 2,418.50` in every locale (render it
  LTR-isolated inside RTL text).
- **Direction (F4, NFR-I18N-02):** logical utilities only (`ms-`, `pe-`,
  `start-`, `text-start`, `border-s`, `rounded-e`). Enforced by
  `resources/js/rules/logical-direction.test.ts`; a line may opt out with
  `f4-allow` and a reason.

## Before every pull request

```sh
composer check
```

This runs `composer validate --strict`, `composer audit`, Pint (check mode),
PHPStan, and the test suite — the exact set CI enforces
(`.github/workflows/ci.yml`).
