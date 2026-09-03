# Engineering Documentation

How the team builds and maintains Nexo. Product requirements live in
[`../product/`](../product/); architectural decisions live in
[`../architecture/`](../architecture/).

| Document | Purpose |
|----------|---------|
| [`architecture-guidelines.md`](architecture-guidelines.md) | Day-to-day rules for structuring code; when an ADR is required |
| [`code-quality.md`](code-quality.md) | Pint, PHPStan/Larastan, the `composer check` gate |
| [`database.md`](database.md) | Migration and schema conventions |
| [`definition-of-done.md`](definition-of-done.md) | The checklist a change must satisfy before merge |
| [`error-handling.md`](error-handling.md) | Exception and logging conventions |
| [`git-workflow.md`](git-workflow.md) | Branching, PRs, commits, merge policy |
| [`issue-workflow.md`](issue-workflow.md) | Epic → Story → Task → PR; issue types |
| [`security.md`](security.md) | Engineering security baseline |
| [`testing.md`](testing.md) | Pest conventions: unit vs feature, naming, fakes |

## Principle

Documentation is part of the system. If a change alters behaviour, architecture,
operations, or product scope, the relevant docs change in the same pull request.
