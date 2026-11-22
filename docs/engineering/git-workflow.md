# Git Workflow

Nexo has one maintainer. It uses a single long-lived branch (`main`) and
short-lived feature branches; there is no `dev`/`develop`/`staging` branch.
Pull requests and independent review are optional. The repository ruleset
enforces CI and history protection instead.

```
main
  │
  ├── feature branch (short-lived, one focused change)
  │     change → tests → validate → commit → push   (repeat per commit)
  │     │
  │     └── CI green on the tip → owner authorises → fast-forward main
  │
main (updated)
```

## What the ruleset enforces on `main`

The `Protect main` ruleset applies to everyone, administrators included: it
has no bypass. GitHub rejects a push to `main` unless:

- both required checks, `PHP 8.5 / Laravel 13` and `Node 24 / Vue 3`, passed on
  the exact commit being pushed. A missing, running, failed or cancelled check
  rejects the push;
- the push is a fast-forward: force-pushes are blocked;
- no merge commit is introduced: history is linear.

Deleting `main` is blocked too. GitHub checks only the tip commit of a push, so
the commits under it are validated locally (see Commits).

## Rules

- **Feature branches by default.** Application changes are made on a feature
  branch cut from the latest `main`. Keep it short-lived: days, not weeks.
- **Push each commit as soon as it is validated.** CI runs on every push to
  every branch. A newer push to the same branch cancels the superseded run;
  runs on `main` always finish.
- **Integrate only with the owner's explicit authorisation**, given for that
  change. Integration fast-forwards `main` to the branch tip once its checks
  are green:

  ```sh
  git fetch origin
  git merge-base --is-ancestor origin/main <sha>   # must succeed: a fast-forward
  git push origin <sha>:refs/heads/main
  ```

- **History is append-only.** Pushed commits are never amended, rebased or
  force-pushed, on any branch. A fix is a new commit; undoing a change on
  `main` is a `git revert` on a branch, integrated like any other change.
- **Pull requests are optional.** Open one for a written record or a review
  conversation. It is integrated the same way, by the fast-forward push; if
  GitHub doesn't mark it merged, close it with a note naming the commits.
  Leave the merge button alone: a squash or rebase merge creates commits CI
  never tested.

## Branch names

`<type>/<short-description>` — e.g. `feature/vendor-approval`,
`fix/checkout-total-rounding`, `chore/bump-larastan`, `docs/testing-guide`.

Types match the issue types in [`issue-workflow.md`](issue-workflow.md):
`feature`, `fix`, `chore`, `refactor`, `security`, `docs`.

## Commits

Commits are atomic and land on `main` exactly as written, so each one is part
of the permanent record.

- **One cohesive change per commit**: one behaviour, one fix with its regression
  test, one infrastructure change, one documentation decision. There is no
  file-count rule.
- **Conventional Commits**: `type(scope): subject`, with type `feat`, `fix`,
  `test`, `refactor`, `docs`, `ci` or `chore`. The subject is imperative and
  states intent: `fix(ordering): freeze the commission rate at order creation`.
- **The body explains why**, the invariant it protects, and how it was
  validated, when the diff doesn't show it.
- **Validate before committing**: the change's tests, Pint, PHPStan and
  `git diff --check`. Run the full gate in
  [`definition-of-done.md`](definition-of-done.md) before integration.

## When `main` has moved on

A branch that no longer fast-forwards onto `main` is replayed, not rebased:

```sh
git switch -c <type>/<description>-2 origin/main
git cherry-pick <first>^..<last>
```

Re-validate each replayed commit, push the new branch, and integrate it once its
tip is green. The old branch stays as it was until it is deleted.

## Scope discipline

One branch = one intent. Do not fold unrelated refactoring, formatting sweeps,
or dependency bumps into a feature branch. If you spot an unrelated problem,
open an issue or start a separate branch. See
[`definition-of-done.md`](definition-of-done.md).
