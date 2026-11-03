# Nexo Documentation

This directory contains the documentation that defines how Nexo is designed,
built, operated, and evolved.

## Documentation Areas

| Directory       | Purpose                                                             |
|-----------------|---------------------------------------------------------------------|
| `architecture/` | Architecture, boundaries, invariants, and ADRs                      |
| `development/`  | Local development and implementation guidance                       |
| `discovery/`    | Phase reports: Phase 0 assessment, Phase 1 review                   |
| `engineering/`  | Engineering process and standards — testing, quality, workflow      |
| `operations/`   | Deployment, infrastructure, monitoring, and incident procedures     |
| `product/`      | Product requirements, scope, personas, goals, and product decisions |

## Start here

- New contributor? The repository [`../README.md`](../README.md) covers setup.
- Building a change? [`engineering/definition-of-done.md`](engineering/definition-of-done.md)
  and [`engineering/git-workflow.md`](engineering/git-workflow.md).
- Understanding the product? [`product/product-requirements.md`](product/product-requirements.md)
  and [`product/user-journeys.md`](product/user-journeys.md).
- Understanding the system? [`architecture/system-overview.md`](architecture/system-overview.md),
  [`architecture/domain-context-map.md`](architecture/domain-context-map.md),
  [`architecture/INVARIANTS.md`](architecture/INVARIANTS.md), and the ADRs in
  [`architecture/adr/`](architecture/adr/).
- How Nexo 2.0 got here? [`discovery/`](discovery/): the Phase 0 assessment and the
  Phase 1 review.

## Documentation Principle

Documentation is part of the system.

If a change alters the behavior, architecture, operational model, or product
scope of Nexo, the relevant documentation must be updated in the same change.
