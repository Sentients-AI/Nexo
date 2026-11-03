# Product Requirements

This directory contains the functional and business requirements for Nexo.

Requirements define **what the system must do**, not how the system should
implement it.

## Requirement hierarchy

```text
Product Vision
      ↓
Scope
      ↓
Requirements
      ↓
Epics
      ↓
Stories
      ↓
Tasks
      ↓
Implementation
```

## Nexo 2.0 product documents (current)

| Document | Purpose |
|---|---|
| [`product-requirements.md`](product-requirements.md) | PRD: objectives, audience, roles, surfaces, scope, release gate |
| [`functional-requirements.md`](functional-requirements.md) | FR-* requirements with acceptance criteria |
| [`non-functional-requirements.md`](non-functional-requirements.md) | NFR-*: correctness, security, performance, a11y, i18n, quality gates |
| [`user-journeys.md`](user-journeys.md) | Customer, vendor and platform-staff workflows with failure branches |

## Superseded (history only)

`vision.md`, `scope.md`, `goals.md` and `personas.md` (aljebal-albeedos framing) were superseded on
2026-11-01 by ADR-0007. `problem-statement.md` and the empty stubs under `requirements/` are
also superseded: `requirements/vendor/onboarding.md` and
`requirements/platform/vendor-management.md` are carried into FR-VEN.
