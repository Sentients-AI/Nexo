# Git Workflow

Nexo uses a single long-lived branch (`main`) and short-lived feature branches.
There is no `dev`/`develop`/`staging` branch.

```
main
  │
  ├── feature branch (short-lived, one focused change)
  │     implementation → tests → Pint → PHPStan → commit
  │     │
  │     └── Pull Request → CI → review → squash merge
  │
main (updated)
```

## Rules

- **No direct commits to `main` for feature work.** `main` is updated only by
  merging a reviewed pull request.
- **No long-lived branches.** A feature branch lives days, not weeks. If work is
  large, split it into several PRs that each land independently.
- **Branch from `main`, PR into `main`.**
- **CI must be green before merge.** Do not merge red. Do not bypass or disable
  a check to merge.
- **PRs require at least one approval**, of the most recent push.
- **Conversations resolved before merge.**
- **Squash merge.** One commit per PR on `main`; linear history.
- **Never force-push `main`.** Force-pushing a feature branch you own is fine.
- **`main` is never rewritten** and its history is protected by the repository
  ruleset (linear history, force-push blocked, branch-deletion restricted).

## Branch names

`<type>/<short-description>` — e.g. `feature/vendor-approval`,
`fix/checkout-total-rounding`, `chore/bump-larastan`, `docs/testing-guide`.

Types match the issue types in [`issue-workflow.md`](issue-workflow.md):
`feature`, `fix`, `chore`, `refactor`, `security`, `docs`.

## Commits

- Present-tense, imperative subject that states intent:
  `Freeze commission rate onto order at creation`, not `changes` or `wip`.
- Body explains **why** when it isn't obvious from the diff.
- Since PRs are squash-merged, the PR title/description becomes the permanent
  record — make it good. Intermediate commits on the branch can be scrappy.

## Keeping a branch current

Rebase your feature branch on `main` (don't merge `main` into it). Resolve
conflicts locally, re-run `composer check`, force-push your branch.

## Scope discipline

One PR = one intent. Do not fold unrelated refactoring, formatting sweeps, or
dependency bumps into a feature PR. If you spot an unrelated problem, open an
issue or a separate PR. See [`definition-of-done.md`](definition-of-done.md).
