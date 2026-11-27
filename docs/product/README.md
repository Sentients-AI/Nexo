# Product Requirements

This directory contains the functional and business requirements for Nexo.

Requirements define **what the system must do**, not how the system should
implement it.

## Requirement hierarchy

```text
Product requirements (PRD)
      ↓
Functional and non-functional requirements
      ↓
Epics
      ↓
Stories
      ↓
Tasks
      ↓
Implementation
```

## Documents

| Document | Purpose |
|---|---|
| [`product-requirements.md`](product-requirements.md) | PRD: objectives, audience, roles, surfaces, scope, release gate |
| [`functional-requirements.md`](functional-requirements.md) | FR-* requirements with acceptance criteria |
| [`non-functional-requirements.md`](non-functional-requirements.md) | NFR-*: correctness, security, performance, a11y, i18n, quality gates |
| [`user-journeys.md`](user-journeys.md) | Customer, vendor and platform-staff workflows with failure branches |
| [`screen-inventory.md`](screen-inventory.md) | Every customer and vendor screen, its layout, status and what it waits for |

## Earlier requirement stubs

`requirements/vendor/onboarding.md` and `requirements/platform/vendor-management.md`
are carried into FR-VEN, which is authoritative. Their `REQ-VENDOR-*` IDs are mapped in
each FR-VEN row.
