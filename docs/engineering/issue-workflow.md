# Issue & Task Workflow

Work is tracked as issues and flows:

```
Epic  →  Story  →  Task  →  Branch (pull request optional)
```

- **Epic** — a large capability spanning multiple stories (e.g. "Split checkout",
  an area in PRD §6.1). Tracks scope and
  sequencing; not implemented directly.
- **Story** — one user-facing capability derived from an approved product
  requirement. Has acceptance criteria written as observable behaviour. Uses the
  [Story issue form](../../.github/ISSUE_TEMPLATE/story.yml).
- **Task** — an implementation slice of a story, or standalone technical work.
  Small enough for one focused branch. Uses the
  [Task issue form](../../.github/ISSUE_TEMPLATE/task.yml).
- **Branch** — implements one task (or a small story) in atomic commits and
  satisfies the [Definition of Done](definition-of-done.md). Put `Closes #NN`
  in the body of its last commit: GitHub closes the issue when that commit
  reaches `main`. Otherwise close it by hand, naming the commits.

Not every change needs the full chain. Issues are recommended for substantial
work and optional for small changes: a typo fix is just a commit. A one-off chore is a
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

Product work is tagged against the PRD scope (`docs/product/product-requirements.md`
§6) and the MoSCoW priority of its FR-* requirement: **M**, **S** or **C**. Work
outside §6.1 is not started without a PRD change. A C item must never block an M
change.
