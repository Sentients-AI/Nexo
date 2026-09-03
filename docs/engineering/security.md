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

## Dependency auditing

- `composer audit` runs in CI and in `composer check`. A new advisory fails the
  build; triage it before merging unrelated work.
- Add a dependency only when this phase needs it. Prefer a small, maintained
  package or a few lines of first-party code over a large transitive tree.

## Reporting

Security issues are reported privately per [`SECURITY.md`](../../SECURITY.md),
never in a public issue or pull request.
