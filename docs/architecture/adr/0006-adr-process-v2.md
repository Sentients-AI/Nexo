# ADR-0006: Adopt the Nexo 2.0 ADR template and status model

- **Number:** 0006
- **Status:** Accepted (settled by the Nexo 2.0 brief §4.3 and §18)
- **Date:** 2026-11-01
- **Decision owner:** Project owner
- **Supersedes:** the template section of ADR-0001 (its "when to write one" rules stay in force)

---

## Context

ADR-0001 introduced ADRs with a four-section template: Context, Decision, Alternatives, Consequences. The Nexo 2.0 brief requires more fields:

- problem;
- decision drivers;
- considered alternatives;
- positive and negative consequences;
- risks;
- testing implications;
- migration implications.

It also requires the statuses Proposed, Accepted, Rejected, Deprecated and Superseded.

Two template files currently exist: the one inside ADR-0001 and `ADR-TEMPLATE.md`. Filenames are inconsistent; `ADR-0005-vendor-naming.md` breaks the `NNNN-kebab-title.md` rule.

## Problem

Which template, statuses and location do Nexo 2.0 ADRs use?

## Decision drivers

- Compliance with the brief.
- One template, not two.
- Keep the existing repository convention, so no churn for its own sake.

## Considered alternatives

### A. Move ADRs to `docs/decisions/ADR-NNNN-*.md` (the brief's example layout)

Rejected. The brief says to adapt to existing repository conventions. `docs/architecture/adr/` already holds ADR-0001 to ADR-0005, and moving them breaks links for no gain.

### B. Keep the location; adopt the brief's fields in `ADR-TEMPLATE.md` (chosen)

## Decision

1. ADRs stay in `docs/architecture/adr/`, named `NNNN-short-kebab-title.md` and numbered sequentially.
2. `ADR-TEMPLATE.md` is the single template. It contains every field the brief requires.
3. Statuses are: Proposed, Accepted, Rejected, Deprecated, and Superseded by ADR-XXXX.
4. **Proposed ADRs are not implemented.** An ADR moves to Accepted only on owner approval, and the approval date is recorded in the status line.
5. After acceptance, only the status line changes. A changed decision needs a new ADR.
6. The ADR index in `docs/architecture/README.md` is updated in the same change as any ADR.

## Positive consequences

- Every significant decision records its testing and migration implications, which feed the Phase 3–5 test plans directly.

## Negative consequences

- Longer ADRs. This is mitigated by keeping one decision per ADR.

## Risks

- ADRs drift from the code. Mitigation: the definition of done requires ADR references in pull requests that touch a decided boundary.

## Testing implications

None directly.

## Migration implications

- ADR-0001 to ADR-0005 keep their original format; they are not rewritten.
- `ADR-0005-vendor-naming.md` should be renamed to `0005-vendor-naming.md` when ADR-0005 is accepted (owner action listed in the Phase 1 review).

## Related

- ADR-0001; the brief §4.3 and §18.
