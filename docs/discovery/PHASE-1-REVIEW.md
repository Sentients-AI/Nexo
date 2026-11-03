# Phase 1 Review: Product Specification and Architectural Foundation

- **Date:** 2026-11-01
- **Branch:** `docs/phase-1-specification` (worktree `C:\laragon\www\nexo-v2`, from `origin/main@1cc7b4e`). Committed as four commits (one per document group) and opened as a PR to `main` on 2026-11-03.
- **Scope:** documentation only. No application code, migrations, dependencies or configuration were changed.

---

## 1. Completed work

| Brief Phase 1 deliverable | Where |
|---|---|
| Product Requirements Document | `docs/product/product-requirements.md` |
| Functional Requirements Specification | `docs/product/functional-requirements.md` (FR-* with acceptance criteria; legacy REQ-VENDOR-* mapped) |
| Non-functional Requirements | `docs/product/non-functional-requirements.md` |
| User roles and permissions | `docs/architecture/security-model.md` (permission matrices) + ADR-0011 |
| Customer and vendor workflows | `docs/product/user-journeys.md` (plus platform-staff journeys) |
| Bounded contexts / Domain Context Map | `docs/architecture/domain-context-map.md` (13 modules + shared kernel, glossary) |
| Dependency rules | `docs/architecture/dependency-rules.md` (layer rules, module matrix, events, read-model exceptions, frontend rules) |
| Database ownership + initial database model | `docs/architecture/database-design.md` |
| Business invariants | `docs/architecture/INVARIANTS.md` (INV-1…21, each with enforcement and proof) |
| Integration contracts / API and frontend contract strategy | `docs/architecture/integration-contracts.md` + ADR-0018 |
| Checkout and payment lifecycle | `docs/architecture/lifecycles/checkout-and-payment.md` (21-row scenario matrix) |
| Inventory lifecycle | `docs/architecture/lifecycles/inventory.md` (13-row scenario matrix) |
| Orders, fulfilment, returns, refunds | `docs/architecture/lifecycles/orders-fulfilment-and-returns.md` |
| Financial model | `docs/architecture/financial-model.md` (balanced worked examples, disputes, payouts, reconciliation) |
| System overview | `docs/architecture/system-overview.md` |
| ADR conventions and ADRs | ADR-0006 (process) + ADR-0007…0021; index in `docs/architecture/README.md` |
| Phase 0 report carried into this repository | `docs/discovery/PHASE-0-TECHNICAL-ASSESSMENT.md` (copy) |

**Owner decisions applied this phase:**

| Decision | Applied as |
|---|---|
| D-1 Vue 3 + Inertia + TS; D-2 this repository | ADR-0008 (Accepted) |
| D-4 supersede the Sudan/aljebal-albeedos product docs | ADR-0007 (Accepted); banners on old docs |
| D-5 MYR, EN-primary, AR/MS peers | ADR-0007 (Accepted) |
| D-6 ledger + simulated payouts | ADR-0016 (core approved) |
| D-7 promotions, reviews, wishlist and follows, loyalty and referrals in scope | PRD §6.1, FR-PRO/LOY/REV/ENG |

## 2. Changed files

**New (32):**

- `docs/architecture/adr/0006…0021` (16 ADRs);
- the 4 product documents above;
- 7 architecture documents;
- `docs/architecture/lifecycles/` (3 files);
- `docs/discovery/PHASE-0-TECHNICAL-ASSESSMENT.md`;
- this file.

**Modified (12):**

| File | Change |
|---|---|
| `docs/README.md` | Start-here links, `discovery/` row |
| `docs/architecture/README.md` | Full index and ADR status table |
| `docs/architecture/INVARIANTS.md` | Rewritten as INV-1…21, with a change table versus the 2026-08-30 version |
| `docs/architecture/adr/ADR-TEMPLATE.md` | Brief §18 fields |
| `docs/product/README.md` | Current vs superseded index (also closes an unclosed code fence) |
| `docs/development/README.md` | Naming note → Vendor |
| `docs/product/{vision,scope,goals,personas,problem-statement}.md`, `docs/architecture/marketplace-migration.md` | One "Superseded" banner line each; content untouched |

**Untouched on purpose:**

- Existing ADR-0001…0005: status changes are yours.
- `docs/engineering/testing.md` and `database.md`: they still say SQLite and change only if ADR-0009 is accepted.
- `docs/vendor-domain-design.md`: a duplicate of `architecture/vendor-domain-design.md`.
- The empty `requirements/*` stubs.

**Other repositories:**

- Old repository `modular-ecommerce-system`: no change in this phase.
- `C:\laragon\www\Nexo` (`feat/vendor-frontend` checkout): untouched. The worktree adds only `.git/worktrees/nexo-v2` metadata and the new local branch.

## 3. Architectural decisions (explained)

These are the significant ones, each with its reason. The trade-offs and alternatives are in each ADR.

1. **Modular monolith with `Contracts`-only cross-module imports, enforced by Pest arch tests (ADR-0010).** Boundaries become visible and CI-checked without purist repository layers. While writing the dependency matrix I found and removed **two cycles**:
   - Ordering↔Finance: Finance now reads its own ledger instead of calling Ordering.
   - Ordering↔Payments: the `OrderPayments` port is owned by Payments and implemented by Ordering.

   I also moved the reservation sweeper from Inventory to Ordering, so Inventory never depends on Payments.
2. **Fail-closed vendor context (ADR-0011).** This directly answers Phase 0 S1: no global scope, no null-means-all fallback, scoped bindings, and an isolation suite as a merge gate.
3. **MySQL for every database test (ADR-0009).** The old repository's concurrency tests passed on SQLite with real bugs present. Correctness claims must run on the real engine.
4. **Checkout saga (ADR-0015):**
   - local transaction;
   - provider call after commit;
   - signed webhook as the only source of payment truth;
   - idempotency keys at the HTTP, provider and event levels.

   Every brief §11 scenario has a defined outcome (P1–P21).
5. **Inventory as on-hand/reserved with conditional updates (ADR-0014).** Overselling is impossible by construction, and duplicate movements are blocked by a unique key.
6. **Refund cap owned by Ordering via guarded counters (ADR-0015 §9).** I first wrote a design where Payments locked Ordering's table. That breaks the module rules, so it was corrected.
7. **Internal double-entry ledger (ADR-0016).** The worked examples in `financial-model.md` were checked by hand to balance: 113.00 / 20.00 / 45.00 / 11.11. They become executable tests in Phase 5.
8. **Outbox + inbox (ADR-0017).** This replaces the old write-only event table (Phase 0 C2).
9. **Generated TypeScript types from PHP data objects (ADR-0018).** This addresses the 22 type errors and the prop drift found in the old repository.

## 4. Tests and verification

- **No application tests were run or needed.** This phase changed only Markdown, and the worktree has no `vendor/` installed.
- **Verification performed:**
  - A script resolved every relative Markdown link under `docs/`. **1 broken:** `marketplace-migration.md` → `../REDESIGN_BRIEF.md`. It pre-exists inside a superseded document and I left it.
  - Line endings were normalised to LF.
  - The financial worked examples balance (checked manually).
  - FR → INV cross-references were checked by hand.
  - The dependency matrix was checked by hand for cycles.
- **Not verified, so these are assumptions:**
  - Filament, `spatie/laravel-data` and `spatie/laravel-typescript-transformer` compatibility with Laravel 13 / PHP 8.5 (ADR-0018, ADR-0020, checked in Phase 3).
  - MYR card + 3-D Secure support in Stripe test mode (D-14, Phase 5).
  - The fee figure in the worked example (RM 4.09) is illustrative.

## 5. Known limitations

- These are specifications. Nothing is implemented, and none of the guarantees exist yet.
- Several product parameters are **proposals** (P-1…P-8 in the PRD) and need your confirmation.
- Tax is out of scope, and prices are tax-inclusive (ADR-0007). A reviewer may notice this, so the README must state it.
- One vendor per user; staff cannot shop; guest checkout is excluded (P-1).
- The admin panel is English-only in v2 (ADR-0020).
- Loyalty points are not a ledger liability (financial-model §1). This is a deliberate simplification.
- MySQL FULLTEXT search with limited Arabic stemming (ADR-0019).

## 6. Unresolved questions (need your decision)

| ID | Question | My recommendation |
|---|---|---|
| ADR status | Accept ADR-0009…0021? | Accept all, or name the ones to revise |
| ADR-0005 | Accept the Vendor naming, mark ADR-0002 superseded, rename the file to `0005-vendor-naming.md` | Yes |
| D-3 | Laravel 13 / PHP 8.5 / MySQL 8.4 (ADR-0009) | Yes |
| D-8 | Search: MySQL first (ADR-0019) | Yes |
| D-9 | Platform bears Stripe fees | Yes |
| D-10 | Commission reversed exactly on refunds; shipping excluded from commission | Yes |
| D-11 | Vendor bears lost-dispute goods value; platform bears the fee | Yes |
| P-1…P-8 | Product parameters in PRD §6.3 | As proposed |
| D-12 follow-up | **Your GitHub account `AdilAzhariOmsan` has read-only access to `AdilAzhari/nexo-ecommerce`.** I could not disable "Deploy Staging" or "Deploy". Run the two `gh workflow disable` commands as the repository owner, or disable them in GitHub → Actions. The server steps in Phase 0 Addendum B.3.1–B.3.2 are still pending on your side. | Do both before Phase 3 |
| D-13 step 3 | Copy `C:\laragon\backups\nexo-2026-11-01\` off-machine | Pending on your side |
| D-15 | Commit author identity. **The repository's local git config is `adelazhary1997@gmail.com`.** Commits made for you will use `adilazhariosman@gmail.com` explicitly. Do you want the history rewritten before going public? | Decide before Phase 3 |
| Commit | May I commit this phase to `docs/phase-1-specification` and open a PR to `main`? | Yes, as one commit per document group |
| Tooling | The repository's `CLAUDE.md` asks for Laravel Boost (`composer require laravel/boost --dev`). It is a dependency, so it was not installed in this docs-only phase. | Install at the start of Phase 3 if you want it |
| 61 React commits | Push `feat/vendor-frontend` to a reference branch on GitHub (they are only bundled locally) | Yes |

## 7. Review checklist

- [ ] PRD objectives, audience and scope (in and out) match your intent; P-1…P-8 confirmed or changed.
- [ ] Functional requirements: priorities (M/S/C) acceptable; nothing missing from your expectations for the three surfaces.
- [ ] NFR targets are acceptable (performance numbers are assumptions until Phase 9 measures them).
- [ ] Roles and permission matrices (`security-model.md`) reflect how you want vendors and staff to work.
- [ ] 13 modules and their ownership (`domain-context-map.md`) make sense; the dependency matrix is acceptable.
- [ ] Database model: naming and constraints acceptable; no table you expected is missing.
- [ ] Invariants INV-1…21 are the right guarantees.
- [ ] Lifecycle scenario matrices cover the failure cases you care about.
- [ ] Financial model: posting rules and worked examples are acceptable; D-9/10/11 decided.
- [ ] ADR-0005 and 0009…0021: accept, revise or reject each.
- [ ] Containment actions (D-12) and the off-machine backup (D-13 step 3) done on your side.
- [ ] Authorise (or not) committing this branch; decide D-15.

**Phase 1 stops here. Phase 2 (Brand Identity and Design System) has not been started.**

---

## Addendum A: owner decisions for Phase 2 readiness (2026-11-04)

The Phase 1 decision briefing (2026-11-03) labelled its spec inconsistencies C1–C13. **Those labels are unrelated to Phase 0's code findings C1–C6** (§7) (for example Phase 0 C1, "payments never marked paid"). Here they are written as "briefing C*n*".

### A.1 Decisions recorded

| Decision | Outcome | Where it is recorded |
|---|---|---|
| ADR-0020: Filament for platform administration | **Accepted.** `/admin` for authorised platform staff only; vendor dashboard stays Vue 3 + Inertia; Filament uses Phase 2 design tokens; the admin is English-only; Filament delegates to use cases and never mutates status or financial data directly; Laravel 13 compatibility is verified in Phase 3, with the fallback retained | ADR-0020 (status line, "Owner decision", Decision §6–§7); `architecture/README.md` ADR index |
| P-1: account-required checkout | **Approved.** Guest browsing and guest cart are allowed; placing an order requires a signed-in account; inline registration at checkout; verified email required before order submission and payment initiation; checkout resumes after verification | PRD §6.3 P-1, §6.1; FR-ID-01, FR-CART-02, FR-CHK-01; journey CJ-1; ADR-0015 §1; security-model §5 |

### A.2 Briefing C7 (P-1 inline sign-up vs FR-ID-01 email verification): resolved

- **The contradiction:**
  - P-1 promised inline sign-up on the checkout page.
  - FR-ID-01 said unverified users are "redirected to verification" before checkout.
  - The flow for a new customer was undefined.
- **The resolution:** verification is a step inside checkout, not a detour out of it.
  - Inline registration signs the customer in and merges the guest cart.
  - Checkout then shows a verification prompt with a resend action.
  - The existing signed, 60-minute link (FR-ID-01) marks the email verified and returns the customer to checkout with the same cart.
  - Placement and payment-attempt requests from unverified accounts are rejected before any write, in the routes and in the use cases.
  - Stock is reserved only at placement, so verifying never uses up a reservation.
- **What was not changed:** the verification mechanism itself (signed link, 60-minute expiry). A one-time-code alternative was considered and not adopted.

### A.3 Consistency edits that follow from ADR-0020 (briefing C13)

ADR-0020 makes the admin English-only. Three statements implied translation of every page, so they are now scoped to the storefront and vendor dashboard:

- PRD §6.1 (i18n row);
- PRD §7 gate 7;
- NFR-I18N-01.

NFR-A11Y-01 was already scoped that way.

### A.4 Still open

- Every other §6 item: ADR-0005, ADR-0009–0019, ADR-0021, D-3, D-8–D-11, P-2–P-8, D-12 follow-up, D-13 step 3, D-15, tooling, and the React reference branch.
- ADR-0020 relies on `platform_role` and the permission enum from ADR-0011, which is still Proposed.
- The Phase 2 readiness items not decided here: provisional order-state vocabulary (ADR-0012), the late-payment transitions (briefing C1), and the Arabic numeral policy (ADR-0018 §3).
