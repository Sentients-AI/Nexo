# Contributing to Nexo

Nexo follows an issue-driven, pull-request-based development workflow.

## Before you start

1. Read the relevant [product documentation](docs/product/).
2. Check existing issues before creating duplicate work.
3. For architectural changes, review the [ADRs](docs/architecture/adr/).
4. For non-trivial work, create or update an issue before implementation. See
   [`docs/engineering/issue-workflow.md`](docs/engineering/issue-workflow.md).

## Development workflow

```text
Issue  →  branch (from main)  →  implementation  →  tests  →  Pint  →  PHPStan
      →  Pull Request  →  CI  →  review  →  squash merge  →  main
```

- The default branch is `main`. Feature work happens on a short-lived branch and
  is never committed directly to `main`.
- Keep pull requests small and focused. Do not mix unrelated refactoring or
  dependency bumps into a feature PR.
- Run `composer check` before opening a PR — it runs the same gates as CI
  (`composer validate --strict`, `composer audit`, Pint, PHPStan, Pest).

The full policy is in [`docs/engineering/git-workflow.md`](docs/engineering/git-workflow.md).

## Pull requests

Every pull request must:

- explain the problem being solved and the approach taken;
- include relevant tests (a bug fix includes a regression test);
- identify any architectural or data-isolation consequences, and link an ADR if
  the change is boundary-shaping;
- note unresolved concerns for the reviewer;
- pass all required CI checks.

`main` requires a passing CI run and at least one approval; PRs are squash-merged.

## Definition of Done

Before requesting review, check your change against
[`docs/engineering/definition-of-done.md`](docs/engineering/definition-of-done.md).

## Questions

If you are unsure whether a change requires an ADR, architectural review, or
additional tests, raise the question on the issue before implementation.
