# Nexo: instructions for coding agents

Nexo is a multi-vendor marketplace: a Laravel 13 / PHP 8.5 modular monolith
(ADR-0010) on MySQL 8.4 everywhere, tests included (ADR-0009), with Vue 3,
Inertia and TypeScript (ADR-0008). One owner maintains it.

## Before you start

- Check the toolchain with `php -v`, `composer -V` and `node -v`. If one is
  missing, report it to the owner. Install tools and packages only with the
  owner's approval; Laravel Boost is not used.
- Run `git status` and `git worktree list`. Work in your own worktree, on a
  feature branch cut from the latest `origin/main`. The owner works in other
  worktrees at the same time (GitHub Desktop, a terminal), so leave their
  branches, stashes and uncommitted changes exactly as you found them.

## Decisions: ADRs first

- Precedence: accepted ADRs (`docs/architecture/adr/`, indexed in
  `docs/architecture/README.md`), then the normative architecture documents
  (`dependency-rules.md`, `domain-context-map.md`, `INVARIANTS.md`), then
  `docs/engineering/`, then the issue text.
- Implement only accepted ADRs. An accepted ADR's body is fixed: only its
  status line changes (acceptance, supersession, an owner-accepted
  implementation interpretation), and the ADR index changes in the same commit
  (ADR-0006). A changed decision is a new ADR.
- When a task conflicts with an accepted ADR, stop and report the conflict with
  the smallest compliant resolution.

## Architecture

- Pragmatic DDD inside the modular monolith. Code lives in
  `app/Modules/<Module>/{Domain,Application,Infrastructure,Http,Contracts,Filament}`;
  a directory appears with its first class. `App\Models` holds only the legacy
  `User`. The rules are in `docs/architecture/dependency-rules.md` and are
  enforced by `tests/Arch`.
- `Domain` stays framework-light: Eloquent is fine, HTTP, Inertia, Filament,
  Livewire and Stripe are not (L1), and of other code it uses only
  `App\Shared`. Modules reach each other through `Contracts`. Every table has
  one owning module (`domain-context-map.md` §2).
- Statuses change only through their state machine (INV-14).
- The seller is a `Vendor`: the `vendors` table, `vendor_id` columns
  (ADR-0005). Tenant names in older documents are historical.

## Security and vendor isolation

- Vendor scoping is explicit and fails closed (ADR-0003, INV-1): queries take an
  explicit vendor id, and a missing vendor context is an error. Global scopes
  are never used for vendor isolation.
- Authorise at the route or action, validate input where it enters, and
  re-check client-supplied ids, amounts and states on the server. Baseline:
  `docs/engineering/security.md`.
- The repository is public: secrets, credentials and real data stay out of it.

## Database

- Database tests run only on the guarded `nexo_testing` databases. Commands
  that drop or reset tables run only there.
- Destructive migrations (dropping, renaming or lossily changing a column or
  table), production data changes and deployments need the owner's explicit
  approval first. Conventions: `docs/engineering/database.md`.
- Prove a database rule with a MySQL-backed Feature test that writes through
  raw queries.

## Git workflow

Full policy: `docs/engineering/git-workflow.md`. The rules agents most often
need:

- One cohesive change per Conventional Commit
  (`feat|fix|test|refactor|docs|ci|chore(scope): subject`), with a body that
  says why. Validate it, then push it to the feature branch immediately.
- History is append-only. Published commits stay as they are: no force-push,
  amend or rebase of a pushed branch. When `main` has moved on, replay the
  commits onto a new branch.
- Integrating into `main` needs the owner's explicit authorisation for that
  change, every time. Integration is a fast-forward of a commit that is green on
  both required checks: `git push origin <sha>:refs/heads/main`. The ruleset has
  no bypass, so a red or unchecked commit is rejected.

## Quality gates

- Before integration, everything in `docs/engineering/definition-of-done.md`
  that applies passes, in the order it gives: `npm ci`, `npm run build`,
  `composer check` (validate, audit, Pint, PHPStan and every Pest suite,
  `tests/Arch` included), `npm run typecheck`, `npm test`,
  `npm audit --omit=dev`.
- CI's required checks, `PHP 8.5 / Laravel 13` and `Node 24 / Vue 3`, must pass
  on the exact commit being integrated. The Redis suite and some Platform
  proofs run only in CI.
- Report results as they ran: name each check, count skipped tests as skipped,
  and say which results are local and which are CI.

## Reporting

After each commit, report its SHA and subject, the files changed, the
validation run and the push result. Finish with the CI run and what remains
open.
