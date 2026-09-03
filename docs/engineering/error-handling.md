# Error Handling & Logging

Use Laravel's built-in exception handling and logging. Do not build a custom
logging or error-handling framework.

## Exceptions

- **Let framework exceptions bubble.** `ModelNotFoundException`,
  `AuthorizationException`, `ValidationException`, and HTTP exceptions already
  render correctly. Do not catch them just to re-throw or to return a hand-rolled
  response.
- **Catch only when you can act.** Catch an exception to add context, to fall
  back to a safe path, to translate an infrastructure error into a domain error,
  or to guarantee cleanup. A `try/catch` that logs and swallows is almost always
  wrong — it hides failure.
- **Use a domain exception when a business rule is violated** and the caller is
  expected to handle it distinctly (e.g. `InsufficientStockException`,
  `VendorNotApprovedException`). Domain exceptions live with their domain, carry
  the data needed to handle them, and extend a common base per context.
- **Never use exceptions for normal control flow.**
- Configure reporting/rendering in `bootstrap/app.php` (`withExceptions`), not by
  overriding the handler class.

## What belongs in logs

- Unexpected failures (5xx, unhandled exceptions, failed jobs).
- Significant state changes and money-moving operations — enough to reconstruct
  what happened: actor, subject id, action, outcome, correlation/request id.
- External calls that fail or retry.

## What must never be logged

- Passwords, password-reset tokens, session tokens, API keys, secrets.
- Full payment details (PAN, CVV), full bank details.
- Personal data beyond the minimum needed to identify a record (prefer ids over
  names/emails/addresses).
- Full request/response bodies for authentication and payment endpoints.

If a value might be sensitive, log an identifier instead of the value.

## Levels

| Level       | Use for                                                        |
|-------------|---------------------------------------------------------------|
| `error`     | a failure that needs attention (unhandled exception, failed payment) |
| `warning`   | a handled anomaly (retry succeeded, degraded fallback used)   |
| `info`      | notable business events (order placed, vendor approved)       |
| `debug`     | local diagnostics only — not relied on in production          |

## Local vs production

- Local: `LOG_LEVEL=debug`, stack/single driver, readable output, `APP_DEBUG=true`.
- Production: `APP_DEBUG=false`, `LOG_LEVEL=info` or `warning`, structured output
  suitable for aggregation. Users see a generic error page; the detail is in the
  log, not the response.

## User-facing errors

Return a helpful, non-leaking message. Validation errors are specific; internal
errors are generic. Never surface a stack trace, SQL, or an internal path to a
user.
