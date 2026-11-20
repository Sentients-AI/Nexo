# Rate limiting: strict and non-strict

Laravel has two kinds of rate limiter. They behave the same when one request
arrives at a time. They differ when many requests for the same key arrive at
once, which is when a limit matters most. Pick the one whose guarantee the
feature needs.

This page describes Laravel 13's behaviour as proven by
`tests/Redis/RateLimiterContractTest.php` (ADR-0022). It does not choose a
limiter for any feature.

## Non-strict: `Illuminate\Cache\RateLimiter`

The `RateLimiter` facade, `RateLimiter::for()` limits and the ordinary
`throttle:` middleware (`ThrottleRequests`) all use it, on any cache store,
including Redis.

- **`hit()` can lose counts at the start of a window.** `increment()` is
  `add(key, 0)`, then `INCRBY`, then `put(key, amount)` when the `add` lost
  and the count equals `amount`. Two processes starting a fresh window
  together can reset a count another process already made, so more requests
  pass than the limit allows. Locally, 7 of 30 four-process trials lost hits.
  Once the window exists, every `hit()` is a single atomic `INCRBY` and counts
  exactly.
- **`attempt()` checks, then counts.** It calls `tooManyAttempts()`, runs the
  callback, then `hit()`. Concurrent callers can all pass the check before any
  of them counts, on every store. The `throttle:` middleware does the same:
  `tooManyAttempts()`, then `hit()`.

Use it where an occasional overshoot under a burst is acceptable: login
throttling as one layer among several, UI-level limits, abuse dampening.

## Strict: `Illuminate\Redis\Limiters\DurationLimiter`

One Lua script checks and counts in a single step on the Redis server, so
concurrent requests cannot overshoot: four processes making 20 attempts at a
limit of 7 admit exactly 7.

- `Redis::throttle('key')->allow($max)->every($seconds)->then(...)` for jobs
  and application code.
- `ThrottleRequestsWithRedis`, the Redis-backed `throttle:` middleware.
  Laravel uses it in place of `ThrottleRequests` when
  `bootstrap/app.php` calls `$middleware->throttleWithRedis()`. It needs the
  Redis cache connection.

Use it where the limit is a guarantee: payment attempts, outbound API quotas,
one-at-a-time operations.

Neither limiter is a substitute for idempotency or for a database constraint
when a duplicate would be harmful.
