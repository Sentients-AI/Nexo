# Security Baseline

This is the engineering baseline every contributor is expected to meet. It is
not the marketplace authorization model (that arrives with the vendor/checkout
work) and it is not a full security audit. For reporting a vulnerability, see
the repository [`SECURITY.md`](../../SECURITY.md).

## Secret management

- Secrets live in environment variables, read through `config/`, never through
  `env()` calls scattered in application code. An `arch()` test enforces this.
- `.env`, `.env.backup`, `.env.production` are git-ignored. Never commit one.
- `.env.example` contains only safe placeholders — no real keys, tokens, URLs,
  or credentials.
- A committed secret is a compromised secret: rotate it, then scrub history.
- Third-party credentials (payment provider, storage, search, mail) are added to
  `.env.example` as empty placeholders with a comment, and to `config/services.php`.

## Environment separation

- `APP_ENV` and `APP_DEBUG` are environment-driven. `APP_DEBUG=true` only in
  local/testing; production runs with `APP_DEBUG=false`.
- Local and test databases are never a production database. The test suite is
  pinned to in-memory SQLite in `phpunit.xml`.
- Destructive or money-touching operations are tested against a staging database
  that is not production.

## Authorization expectations

- Every route that exposes vendor-owned or user-owned data checks authorization
  — a policy, a gate, form-request authorization, or an explicit ownership
  check. "The UI doesn't link to it" is not authorization.
- When vendor scoping lands (ADR-0003), vendor-context queries are scoped to the
  authenticated vendor explicitly and that scoping is enforced at a base layer,
  not left to each query. Cross-vendor read/write denial is a merge-gate test
  suite.
- Default to deny. Add access deliberately.

## Input validation

- Validate at the boundary: form requests for HTTP, explicit validation for
  console/queue input, signature verification for webhooks.
- Never trust client-supplied IDs, prices, totals, quantities, or state
  transitions — re-derive or re-check them server-side.
- Use Eloquent / the query builder with bindings. No string-interpolated SQL.

## Sensitive data handling

- Passwords are hashed (`hashed` cast). Tokens are hashed at rest where the
  framework supports it.
- Model `$hidden` / the `Hidden` attribute keeps secrets out of serialized
  output.
- Do not put personal data, card data, or full tokens in URLs, flash messages,
  or client-visible errors.

## Logging

See [`error-handling.md`](error-handling.md). In short: never log passwords,
tokens, API keys, full card numbers, or full request bodies for auth/payment
endpoints.

## Security headers

`App\Http\Middleware\SecurityHeaders` runs first in the global stack, so
every response gets them, error and maintenance pages included
(NFR-SEC-08, tested in `tests/Feature/SecurityHeadersTest.php`):

- **CSP** with a fresh nonce per request and no `unsafe-inline` or
  `unsafe-eval`: `default-src 'self'`, scripts and styles from `'self'`
  plus the nonce, `object-src 'none'`, `base-uri 'self'`,
  `form-action 'self'`, `frame-ancestors 'none'`. Laravel's Vite helper
  adds the nonce to `@vite` and `@fonts` tags; the `csp-nonce` meta in
  `app.blade.php` passes it to Vite and Inertia for styles they inject.
  While `npm run dev` runs, the Vite origin, its WebSocket and inline
  styles are allowed (development only).
- `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`,
  `Cross-Origin-Opener-Policy: same-origin`, and a `Permissions-Policy`
  denying camera, microphone and geolocation.
- **HSTS** (`max-age=31536000`) on HTTPS requests outside local
  development. Behind a TLS-terminating proxy the demo host must be a
  trusted proxy, or requests will not count as HTTPS.
- Adding a third-party origin (payments, analytics, images) means adding
  it to the policy in the same PR, with a test.

## Dependency auditing

- `composer audit` runs in CI and in `composer check`. A new advisory fails the
  build; triage it before merging unrelated work.
- `npm audit --omit=dev` runs last in the `Node 24 / Vue 3` CI job and is
  blocking. It covers every package shipped to browsers. An advisory that
  reaches a production dependency blocks merging immediately.
- Development-only npm advisories do not block CI, but each one is recorded
  below with its package chain, why it is accepted and when it was last
  reassessed. Reassess with a full `npm audit` whenever `@vue/test-utils` or
  `vitest` is updated, and at the start of each milestone.

  | Advisory | Chain | Why accepted | Last reassessed |
  |---|---|---|---|
  | [GHSA-3g3m-2fjr-6pw4](https://github.com/advisories/GHSA-3g3m-2fjr-6pw4) (moderate, `glob` 13.0.6) | `@vue/test-utils` → `js-beautify` → `glob` | Test-time only; never bundled or shipped. No fixed `@vue/test-utils` 2.5.x yet; `npm audit fix --force` would downgrade to 2.4.11 | 2026-11-08 |
  | [GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249) (moderate, `minimatch` 10.2.6) | `@vue/test-utils` → `js-beautify` → `editorconfig` / `glob` → `minimatch` | Same as above; inputs are our own repository paths, not untrusted patterns | 2026-11-08 |

- Add a dependency only when this phase needs it. Prefer a small, maintained
  package or a few lines of first-party code over a large transitive tree.

## Reporting

Security issues are reported privately per [`SECURITY.md`](../../SECURITY.md),
never in a public issue or pull request.
