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

## Before every pull request

```sh
composer check
```

This runs `composer validate --strict`, `composer audit`, Pint (check mode),
PHPStan, and the test suite — the exact set CI enforces
(`.github/workflows/ci.yml`).
