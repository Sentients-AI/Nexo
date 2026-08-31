# ADR-0001: ADR template and process

**Status:** Accepted
**Date:** 2026-08-30

---

## What an ADR is

An Architecture Decision Record captures **one** boundary-shaping decision: why it was
made, what was rejected, and what we now have to live with. It is written when the
decision is made, and it is not edited afterwards except to change its `Status`
(e.g. `Superseded by ADR-0042`).

## Relationship to `docs/DECISIONS.md`

`docs/DECISIONS.md` is the pre-2026-08-30 chronological log of decisions made while the
system was a multi-tenant SaaS. It stays as history. From 2026-08-30 onward, every
boundary-shaping decision gets a numbered ADR in this folder instead.

## When to write one

Write an ADR when a choice:

- moves a domain boundary, or changes what a bounded context means;
- changes how data is isolated between vendors;
- changes the money flow;
- adds or removes a runtime dependency;
- changes the deployment or environment model;
- reverses an earlier ADR.

Do **not** write one for routine implementation choices covered by
`docs/development/coding-standards.md` (to be written).

## Numbering

Zero-padded, sequential: `0002`, `0003`, … Filename: `NNNN-short-kebab-title.md`.

## Template

```markdown
# ADR-NNNN: <short imperative title>

**Status:** Proposed | Accepted | Superseded by ADR-XXXX
**Date:** YYYY-MM-DD

---

## Context

What is forcing a decision now. The constraints. What we know and don't know.

## Decision

The choice, stated plainly. What we will do.

## Alternatives considered

Each realistic option, and the specific reason it lost.

## Consequences

What becomes easier. What becomes harder. What we now have to maintain, test, or watch.
New follow-up work this creates.
```
