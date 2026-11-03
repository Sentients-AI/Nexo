# ADR-0008: Build Nexo 2.0 in this repository with Vue 3, Inertia and TypeScript

- **Number:** 0008
- **Status:** Accepted, by owner decisions D-1 and D-2 (2026-11-01)
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

Phase 0 found two candidate foundations.

**The old repository `AdilAzhari/nexo-ecommerce`:**

- Laravel 12, Vue 3, Inertia 2, Filament.
- Extensive, but with critical defects in payments, inventory and vendor authorisation.
- Its CI/CD is connected to a production database.

**This repository `Sentients-AI/Nexo`:**

- A clean Laravel 13 / PHP 8.5 shell: Pest 5, Larastan, Pint, arch tests and an engineering handbook.
- Branch `feat/vendor-frontend` (61 commits) holds a tested React 19 vendor workspace.
- `main` contains no frontend framework.

The brief's technology baseline names Vue and Inertia.

## Problem

Which repository is the foundation, and which frontend framework does v2 use?

## Decision drivers

- A clean history and no inherited production coupling.
- Alignment with the brief's baseline.
- Reuse of the highest-quality existing work, where it does not compromise the above.

## Considered alternatives

### A. Continue in the old repository on an orphan branch

Rejected (D-2). It inherits deploy pipelines wired to production and a confusing history.

### B. Use this repository with React, reusing `feat/vendor-frontend`

Rejected (D-1). The owner prefers the brief's Vue baseline.

### C. Use this repository with Vue 3 + Inertia + TypeScript, keeping the React work as reference (chosen)

## Decision

1. `Sentients-AI/Nexo` is the Nexo 2.0 repository. The old repository is a read-only historical reference.
2. v2 starts from `main`, the Laravel 13 shell. `feat/vendor-frontend` is not merged.
3. The frontend is **Vue 3 (Composition API, `<script setup>`) + `@inertiajs/vue3` + TypeScript in strict mode**, built by Vite and styled with Tailwind 4.
   - The exact Inertia major version is chosen in Phase 3. It must match `inertiajs/inertia-laravel`.
   - Frontend unit and component tests use Vitest + Vue Test Utils.
4. **Reference material from the React workspace** may be ported, but not copied as code:
   - design tokens;
   - framework-neutral TypeScript in `lib/` (money, format, status, countdown);
   - EN/AR locale strings;
   - the component inventory;
   - `docs/frontend/vendor-frontend-spec.md`;
   - the design handoff.
5. Nothing from the old repository's application code is copied wholesale. Individual pieces may be ported only when a Phase 3+ task cites them and re-tests them.

## Positive consequences

- A clean, single-purpose history for the portfolio.
- No inherited production coupling.
- The brief's stack is honoured.

## Negative consequences

- About 12k lines of tested React UI are not reused directly, so the component kit is rebuilt in Vue in Phase 2/7.

## Risks

- The 61 React commits exist only locally. They are preserved by bundle in `C:\laragon\backups\nexo-2026-11-01\`. The owner should also push them to a reference branch (open item).
- The history has mixed author emails (D-15). This must be settled before the repository is shown publicly.

## Testing implications

CI gains these steps:

- `npm ci`
- `vue-tsc --noEmit`
- ESLint
- Vitest
- `vite build`

## Migration implications

- The React-specific files on `feat/vendor-frontend` are never merged to `main`.
- The Phase 0 report is copied to `docs/discovery/`.

## Related

- ADR-0018 (contracts); Phase 0 report §A.4; ADR-0020 (admin).
