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
  emits `hreflang` alternates plus `x-default`. They are the shared
  `alternates` prop: Blade renders them for the first response and
  Inertia's `serverHead` (`resources/js/i18n/alternates.ts`) replaces them
  on every client-side visit. The language switcher reads the same prop,
  never the DOM.
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

## Component kit

- **Location:** `resources/js/shared/ui/`: shared by storefront and vendor
  pages (rule F1). The gallery at `/dev/components` (local only) shows every
  component in en, ms and ar.
- **Components:** `AppButton`, `TextLink`, `FormField` + `TextInput` /
  `SelectInput`, `CheckboxInput`, `StatusBadge` (generic `tone`),
  `SurfaceCard`, `ModalDialog`, `ToastRegion` (+ `toasts.ts`),
  `LoadingSkeleton`, `EmptyState`, `MoneyPrice`, `PageNav`. Names are
  multi-word to stay clear of HTML elements and Inertia's `Link`.
- **Forms:** put the control inside `<FormField label hint error>`; it gets
  its id, `aria-describedby` (error, then hint), `aria-invalid` and
  `required` automatically (NFR-A11Y-02). Email, URL and phone inputs are
  always LTR.
- **Dialogs** use the native `<dialog>` with `showModal()` (inert
  background, focus trap, Escape) and return focus to the trigger.
- **Toasts:** call `push(tone, message)` in the browser, or send
  `Inertia::flash('toast', ['tone' => 'success', 'key' => '…', 'params' => [...]])`
  from the server; the key is translated in the browser. Success and info
  toasts auto-dismiss (paused on hover or focus); errors stay until dismissed.
- **Money** is shown only through `MoneyPrice` (LTR-isolated, no
  arithmetic, F3).
- **Tests:** every component has Vitest + Vue Test Utils tests including
  keyboard/focus behaviour where it applies and an axe check
  (`resources/js/test/axe.ts`, NFR-A11Y-04; colour contrast is covered by
  the token tests instead). jsdom has no `<dialog>`, so
  `resources/js/test/setup.ts` stubs `showModal`/`close`; real browser
  behaviour is checked manually and in Phase 9 browser tests.

## Layouts

- **Storefront** (`resources/js/storefront/layouts/StorefrontLayout.vue`):
  skip link, header (brand, language switcher, cart count), `<main
  id="main">`, toasts. **Checkout** (`CheckoutLayout.vue`): the same frame
  with a quiet header and no cart or browsing links. **Vendor**
  (`resources/js/vendor/layouts/VendorLayout.vue`): static menu, no auth
  until ADR-0011; sections without a page are text, never dead links.
- A page picks its layout with `defineOptions({ layout: StorefrontLayout })`.
  Inertia keeps it mounted across visits.
- Shared frame pieces (`SkipLink`, `LocaleSwitcher`) live in
  `resources/js/shared/layout/` so both surfaces can use them (F1).
- Local previews: `/dev/layouts/checkout` and `/dev/layouts/vendor`, in
  every locale (`/ms/...`, `/ar/...`).
- Which screen uses which layout, and what each one waits for:
  [`screen-inventory.md`](../product/screen-inventory.md).

## Error pages

`App\Http\Errors\ErrorPages` (registered in `bootstrap/app.php`) renders
403, 404, 419, 500 and 503 as the Inertia page `Errors/ErrorPage`, in the
URL's locale. It receives only the status code (NFR-SEC-07). JSON clients
keep JSON errors; with `APP_DEBUG=true` a 500 shows Laravel's debug page.

## Before every pull request

```sh
composer check
```

This runs `composer validate --strict`, `composer audit`, Pint (check mode),
PHPStan, and the test suite — the exact set CI enforces
(`.github/workflows/ci.yml`).
