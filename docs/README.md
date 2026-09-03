# Nexo Documentation

This directory contains the documentation that defines how Nexo is designed,
built, operated, and evolved.

## Documentation Areas

| Directory       | Purpose                                                             |
|-----------------|---------------------------------------------------------------------|
| `architecture/` | Architecture, boundaries, invariants, and ADRs                      |
| `development/`  | Local development and implementation guidance                       |
| `engineering/`  | Engineering process and standards — testing, quality, workflow      |
| `operations/`   | Deployment, infrastructure, monitoring, and incident procedures     |
| `product/`      | Product requirements, scope, personas, goals, and product decisions |

## Start here

- New contributor? The repository [`../README.md`](../README.md) covers setup.
- Building a change? [`engineering/definition-of-done.md`](engineering/definition-of-done.md)
  and [`engineering/git-workflow.md`](engineering/git-workflow.md).
- Understanding the system? [`product/vision.md`](product/vision.md),
  [`architecture/INVARIANTS.md`](architecture/INVARIANTS.md), and the ADRs in
  [`architecture/adr/`](architecture/adr/).
- Where the marketplace is headed? [`architecture/marketplace-migration.md`](architecture/marketplace-migration.md).

## Documentation Principle

Documentation is part of the system.

If a change alters the behavior, architecture, operational model, or product
scope of Nexo, the relevant documentation must be updated in the same change.
