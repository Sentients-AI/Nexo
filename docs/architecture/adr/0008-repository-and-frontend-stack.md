# ADR-0008: Build the frontend with Vue 3, Inertia and TypeScript

- **Number:** 0008
- **Status:** Accepted, by owner decision D-1 (2026-11-01)
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

`main` is a clean Laravel 13 / PHP 8.5 shell: Pest 5, Larastan, Pint, arch tests and an engineering handbook. It contains no frontend framework yet.

Branch `feat/vendor-frontend` (61 commits) holds a tested React 19 prototype of the vendor workspace. It answered most of the open UX questions for vendors: the component inventory, the dense table and form layouts, the EN/AR strings and a full design handoff.

The brief's technology baseline names Vue and Inertia.

## Problem

Which frontend framework does Nexo use, and what happens to the React prototype?

## Decision drivers

- Alignment with the brief's baseline.
- One frontend stack for the storefront and the vendor dashboard, so the component kit, tokens and i18n are written once.
- Reuse of what the prototype learned, without inheriting code written before the architecture was fixed.

## Considered alternatives

### A. React, merging `feat/vendor-frontend`

Rejected (D-1). The owner prefers the brief's Vue baseline, and the prototype predates the module boundaries and page contracts (ADR-0010, ADR-0018).

### B. Vue 3 + Inertia + TypeScript, keeping the React prototype as reference (chosen)

## Decision

1. The application is built from `main`. `feat/vendor-frontend` is not merged.
2. The frontend is **Vue 3 (Composition API, `<script setup>`) + `@inertiajs/vue3` + TypeScript in strict mode**, built by Vite and styled with Tailwind 4.
   - The exact Inertia major version is chosen in Phase 3. It must match `inertiajs/inertia-laravel`.
   - Frontend unit and component tests use Vitest + Vue Test Utils.
3. **Reference material from the React prototype** may be ported, but not copied as code:
   - design tokens;
   - framework-neutral TypeScript in `lib/` (money, format, status, countdown);
   - EN/AR locale strings;
   - the component inventory;
   - `docs/frontend/vendor-frontend-spec.md`;
   - the design handoff.

## Positive consequences

- One stack across every customer and vendor surface.
- The brief's stack is honoured.
- The prototype's UX decisions carry over without its code.

## Negative consequences

- About 12k lines of tested React UI are not reused directly, so the component kit is rebuilt in Vue in Phase 2/7.

## Risks

- The 61 React commits exist only locally. They are preserved by bundle in `C:\laragon\backups\nexo-2026-11-01\`. The owner should also push them to a reference branch (open item).

## Testing implications

CI gains these steps:

- `npm ci`
- `vue-tsc --noEmit`
- ESLint
- Vitest
- `vite build`

## Migration implications

- The React-specific files on `feat/vendor-frontend` are never merged to `main`.

## Related

- ADR-0018 (contracts); ADR-0020 (admin).
