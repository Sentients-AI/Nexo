# Contributing to Nexo

Nexo has one maintainer and a short-lived-branch workflow: atomic commits on a
feature branch, CI on every push, and a fast-forward of `main` once the owner
authorises it. Pull requests are optional; issues are recommended for
substantial work and optional for small changes.

## Before you start

1. Read the relevant [product documentation](docs/product/).
2. For architectural changes, review the [ADRs](docs/architecture/adr/).
3. For substantial work, create or update an issue before implementation; a
   small change can go without one. See
   [`docs/engineering/issue-workflow.md`](docs/engineering/issue-workflow.md).

## Development workflow

```text
(issue) → branch (from main) → change + tests → validate → commit → push
        → … one commit per change …
        → CI green on the tip → owner authorises → fast-forward main
```

- The default branch is `main`. Application changes are made on a short-lived
  feature branch.
- Each commit is one cohesive Conventional Commit, validated and pushed as soon
  as it is made. Do not mix unrelated refactoring or dependency bumps into a
  feature branch.
- Before integration, run the full gate in the Definition of Done:
  `composer check` runs the PHP gates CI runs (`composer validate --strict`,
  `composer audit`, Pint, PHPStan, Pest).
- `main` accepts only fast-forwards to a commit that passed both required
  checks, `PHP 8.5 / Laravel 13` and `Node 24 / Vue 3`. No one can bypass this.
  Integration needs the owner's explicit authorisation.

The full policy is in [`docs/engineering/git-workflow.md`](docs/engineering/git-workflow.md).

## Pull requests (optional)

Open a pull request when you want a written record or a review conversation.
Its description should:

- explain the problem being solved and the approach taken;
- list the tests (a bug fix includes a regression test);
- identify any architectural or data-isolation consequences, and link an ADR if
  the change is boundary-shaping;
- note unresolved concerns.

A pull request is integrated by the same fast-forward push, never by the merge
button.

## Definition of Done

Before integration, check your change against
[`docs/engineering/definition-of-done.md`](docs/engineering/definition-of-done.md).

## Questions

If you are unsure whether a change requires an ADR, architectural review, or
additional tests, raise the question with the owner, on an issue if there is
one, before implementation.
