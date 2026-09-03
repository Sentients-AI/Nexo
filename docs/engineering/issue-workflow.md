# Issue & Task Workflow

Work is tracked as issues and flows:

```
Epic  →  Story  →  Task  →  Pull Request
```

- **Epic** — a large capability spanning multiple stories (e.g. "Split checkout",
  a phase in `docs/architecture/marketplace-migration.md`). Tracks scope and
  sequencing; not implemented directly.
- **Story** — one user-facing capability derived from an approved product
  requirement. Has acceptance criteria written as observable behaviour. Uses the
  [Story issue form](../../.github/ISSUE_TEMPLATE/story.yml).
- **Task** — an implementation slice of a story, or standalone technical work.
  Small enough for one focused PR. Uses the
  [Task issue form](../../.github/ISSUE_TEMPLATE/task.yml).
- **Pull Request** — implements one task (or a small story), links the issue with
  `Closes #NN`, and satisfies the [Definition of Done](definition-of-done.md).

Not every change needs the full chain. A typo fix is a PR. A one-off chore is a
Task. Only product-driven work needs a Story with a requirement behind it.

## Issue types (labels)

| Type            | Use when                                                                 |
|-----------------|-------------------------------------------------------------------------|
| `feature`       | new product capability. Needs a problem, affected personas, success criteria, scope tag. |
| `bug`           | behaviour that violates a stated requirement or invariant. Needs repro steps, expected vs actual, a regression test on fix. |
| `chore`         | maintenance with no behaviour change — dependency bumps, CI tweaks, config, tooling. |
| `refactor`      | internal structure change, behaviour identical. Must state why now and how "no behaviour change" is verified (tests unchanged and passing). |
| `security`      | a security weakness or hardening task. Sensitive details reported privately per `SECURITY.md`, not in the public issue. |
| `documentation` | docs only — engineering docs, ADR follow-ups, README, product docs. |

`feature`/`bug` map to the GitHub issue forms in `.github/ISSUE_TEMPLATE/`.
`chore`/`refactor`/`security`/`documentation` can use the Task form or a plain
issue; keep the same headings.

## What every issue must answer

A developer picking up an issue should be able to tell, without asking:

1. **Why it exists** — the problem or requirement behind it.
2. **Expected behaviour** — what "working" looks like from the outside.
3. **Acceptance criteria** — checkable, ideally Given/When/Then.
4. **Technical constraints** — relevant ADRs, invariants, existing code, data.
5. **Testing expectations** — what kind of tests prove it, and any edge cases
   that must be covered.

If an issue can't answer these, it's not ready to start — clarify it first.

## Scope tags

Product work is tagged against `docs/product/scope.md`: **Core**, **Extended**,
**Experimental**, **Deferred**. An Experimental item must never block a Core
change.
