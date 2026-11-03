# ADR-0018: Define Inertia page contracts as PHP data objects and generate TypeScript types from them

- **Number:** 0018
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

Phase 0 found 22 `vue-tsc` errors in the old repository, and that its prop types were hand-written and had drifted from the controllers.

The brief (§12) asks for explicit contracts covering:

- requests;
- responses;
- TypeScript types and shared props;
- pagination;
- errors;
- money, date and enum serialisation.

It also asks for no duplicated business rules between PHP and TypeScript.

## Problem

How are the shapes exchanged between Laravel and Vue defined once and kept in sync?

## Decision drivers

- One source of truth for every prop shape.
- Drift fails CI.
- No business logic in the browser.

## Considered alternatives

### A. Hand-written TypeScript interfaces

Rejected. They drift, as in the old repository.

### B. A REST API with OpenAPI and a generated client

Rejected for v2 surfaces. Inertia is the transport, and a public API is out of scope (PRD §6).

### C. `spatie/laravel-data` DTOs as page props, plus `spatie/laravel-typescript-transformer` to generate `.d.ts` (chosen)

- These are two new dependencies, justified because they remove a whole class of drift.
- Exact versions are verified for Laravel 13 compatibility in Phase 3. If they are incompatible, the fallback is a small first-party transformer over PHP attributes.

## Decision

1. **Every Inertia page** receives props built from `*PageData` / `*Data` classes in the module's `Http/` namespace. Arrays built ad hoc in controllers are forbidden by an arch test.
2. **Generated types** are written to `resources/js/types/generated.d.ts` by `php artisan typescript:transform`.
   - The file is committed.
   - CI regenerates it and fails on any diff.
3. **Serialisation rules:**
   - **Money:** `{ amount: number /* integer minor units */, currency: 'MYR' }`. The frontend formats it with `Intl.NumberFormat(locale, { style: 'currency', currency })`. For Arabic it uses the `-u-nu-latn` extension, so prices use Latin digits; this is a design-system decision to confirm in Phase 2.
   - **Dates:** ISO 8601 strings in UTC with an offset. They are displayed in `Asia/Kuala_Lumpur` by default.
   - **Enums:** PHP backed enums become TypeScript string-literal unions. Labels come from translation keys `enums.<Enum>.<case>`, never hard-coded in Vue.
   - **IDs:** public ULIDs or references only. Internal auto-increment IDs are never exposed.
   - **Nullable** stays `T | null`, never optional-undefined, so every key is always present.
4. **Pagination:**
   - The generic `Paginated<T>` is `{ data: T[], meta: { currentPage, perPage, total, lastPage }, links: { prev: string|null, next: string|null } }`.
   - Cursor pagination is used for large vendor and admin lists, as `CursorPaginated<T>`.
5. **Shared props** (`HandleInertiaRequests`), each typed by a `SharedData` class:
   - `auth.user`;
   - `auth.vendor`, present only in vendor context;
   - `locale` and `direction`;
   - `flash`;
   - `cart.count`.
6. **Errors:**
   - Validation errors use the Inertia errors bag (422), keyed by field.
   - Domain errors (insufficient stock, price changed, invalid transition) are thrown as typed `DomainException`s with a stable `code`, and rendered as a flash `{ code, message }` or a page-level error component.
   - The JSON endpoints that exist (webhooks, the polling endpoint for payment status) return `application/problem+json` (RFC 9457) with a `code` extension.
7. **Requests:**
   - Form requests validate. The generated TypeScript types for request payloads come from the same `*Data` classes when practical.
   - **The server re-derives every price, total and permission.** The client only displays.
8. **Business rules are not duplicated in TypeScript.** For example, refundable amounts, allowed transitions and stock availability are sent as computed fields (`canCancel`, `refundableAmount`, `allowedTransitions[]`).

## Positive consequences

- Drift becomes a CI failure.
- The UI cannot drift into making business decisions.

## Negative consequences

- Two dependencies, and a generation step in the workflow.

## Risks

- Package incompatibility with Laravel 13. Mitigation: verify in Phase 3, with the first-party fallback above.

## Testing implications

- `assertInertia` tests per page checking the component and the prop keys and types.
- The CI type-generation diff check.
- `vue-tsc --noEmit` as a gate.

## Migration implications

None.

## Related

- `integration-contracts.md`, ADR-0008, ADR-0013.
