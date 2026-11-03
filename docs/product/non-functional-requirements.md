# Non-functional Requirements

- **Status:** Draft for owner review (Phase 1)
- **Date:** 2026-11-01

These requirements are measurable. Where a target is an assumption rather than a measurement, it says so. Targets apply to the **demo environment** at demo scale. Demo scale is about 12 vendors, ≤ 1,000 products and ≤ 5,000 orders (PRD §8).

## NFR-COR: Correctness (highest priority)

| ID | Requirement | Verification |
|---|---|---|
| NFR-COR-01 | No overselling under concurrent checkout | Parallel-process MySQL tests (ADR-0014) |
| NFR-COR-02 | Every ledger transaction balances; every business event posts at most once | Property tests + nightly invariant job |
| NFR-COR-03 | Every brief §10 and §11 scenario has a defined outcome and an automated test | Scenario matrix in `lifecycles/*.md`, each row linked to a test name in Phase 4–5 |
| NFR-COR-04 | Retries (user, queue, webhook, scheduler) are idempotent | Duplicate-execution tests per use case |

## NFR-SEC: Security

| ID | Requirement | Verification |
|---|---|---|
| NFR-SEC-01 | OWASP ASVS 4.0 Level 1 controls for authentication, session, access control, validation, and error handling and logging | Phase 9 checklist |
| NFR-SEC-02 | Default-deny authorisation; vendor isolation suite is a merge gate | ADR-0011 |
| NFR-SEC-03 | Staff 2FA mandatory; staff sessions expire after 2 hours idle | Tests |
| NFR-SEC-04 | Webhook signature verification; CSRF on all state-changing web routes; rate limits on auth, checkout, coupon validation and review creation | Tests |
| NFR-SEC-05 | Uploads: images only (JPEG, PNG, WebP), ≤ 5 MB, re-encoded server-side, randomised names, EXIF stripped | Tests |
| NFR-SEC-06 | No secrets in the repository; live payment keys refused at boot (ADR-0021); `composer audit` and `npm audit --omit=dev` gate CI | CI |
| NFR-SEC-07 | Production error pages never expose exception messages or stack traces (Phase 0 S-finding) | Test with `APP_DEBUG=false` |
| NFR-SEC-08 | Security headers: CSP (nonce-based for Vite), HSTS on demo, `X-Content-Type-Options`, `Referrer-Policy`, `frame-ancestors 'none'` | Header tests |

## NFR-PERF: Performance (assumption-based targets; measured in Phase 9)

| ID | Requirement |
|---|---|
| NFR-PERF-01 | Server time p95 ≤ 300 ms for storefront pages (home, category, product, search) at demo scale |
| NFR-PERF-02 | `POST /checkout` server time p95 ≤ 800 ms, excluding the provider call |
| NFR-PERF-03 | No N+1 queries on list pages; enforced with `Model::preventLazyLoading()` outside production, plus query-count assertions on key pages |
| NFR-PERF-04 | Largest Contentful Paint ≤ 2.5 s on a mid-range mobile profile (Lighthouse) for home and product pages; responsive images in WebP with explicit dimensions |
| NFR-PERF-05 | Initial JS for the storefront entry ≤ 200 KB gzip; vendor and admin code split from storefront |

## NFR-REL: Reliability and operability

| ID | Requirement |
|---|---|
| NFR-REL-01 | Outbox, sweeper and reconciliation jobs are idempotent and safe to run concurrently |
| NFR-REL-02 | Health endpoint reports DB, queue, outbox age, scheduler heartbeat and last webhook time |
| NFR-REL-03 | Structured JSON logs with a correlation id propagated from request → jobs → outbox consumers |
| NFR-REL-04 | Failed jobs and dead-lettered messages are visible and retryable from the admin panel |
| NFR-REL-05 | Demo database backed up daily and restorable via the documented procedure (or rebuilt from the seed) |

## NFR-A11Y: Accessibility

| ID | Requirement |
|---|---|
| NFR-A11Y-01 | WCAG 2.2 AA for storefront and vendor dashboard key journeys (browse, cart, checkout, order tracking, vendor fulfilment) |
| NFR-A11Y-02 | Full keyboard operation, visible focus, semantic landmarks, labelled form controls, error messages linked with `aria-describedby` |
| NFR-A11Y-03 | Colour contrast ≥ 4.5:1 for text (verified against Phase 2 tokens) |
| NFR-A11Y-04 | Automated axe checks in browser tests; manual screen-reader pass (NVDA) on checkout in Phase 9 |

## NFR-I18N: Internationalisation

| ID | Requirement |
|---|---|
| NFR-I18N-01 | en, ar and ms have 100 % key coverage for the UI; CI fails on missing keys |
| NFR-I18N-02 | RTL uses CSS logical properties and direction-aware components (icons, carousels, steppers, progress); no physical `left/right` utilities in components (lint rule) |
| NFR-I18N-03 | Currency, number and date formatting is locale-aware through `Intl`; dates display in MYT |
| NFR-I18N-04 | Locale in the storefront URL (`/ms/...`, `/ar/...`; `en` unprefixed) for shareable links; `hreflang` alternates |

## NFR-MNT: Maintainability and quality gates

| ID | Requirement |
|---|---|
| NFR-MNT-01 | CI gates (all blocking): Pint, PHPStan/Larastan level 8 (raised from the current 6 during Phase 3), Pest (unit + feature + arch on MySQL), concurrency suite, `vue-tsc`, ESLint, Vitest, Vite build, generated-types diff, translation-key check, `composer audit`, `npm audit` |
| NFR-MNT-02 | Module dependency rules enforced by arch tests (ADR-0010) |
| NFR-MNT-03 | Every boundary decision has an ADR; PRs reference the ADRs they implement |
| NFR-MNT-04 | No test is skipped in CI without a linked issue; skipped tests are reported in CI output |

## NFR-COMP: Compatibility

| ID | Requirement |
|---|---|
| NFR-COMP-01 | Latest two versions of Chrome, Edge, Firefox and Safari; iOS Safari 17+; Android Chrome |
| NFR-COMP-02 | Responsive from 360 px wide; storefront is mobile-first; vendor dashboard is fully usable at ≥ 768 px and functional on mobile for order fulfilment |

## NFR-PRIV: Privacy and data

| ID | Requirement |
|---|---|
| NFR-PRIV-01 | All demo personal data is fictional; no real customer data is ever imported |
| NFR-PRIV-02 | Vendors see only the customer data needed to fulfil (name, shipping address, phone); never email or other orders |
| NFR-PRIV-03 | Card data never touches Nexo servers (Stripe Elements / Payment Element) |
| NFR-PRIV-04 | Logs never contain passwords, tokens, full addresses or provider secrets |
