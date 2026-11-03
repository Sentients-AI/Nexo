# Development Documentation

Practical guidance for developing Nexo locally.

## Getting started

New-contributor setup — prerequisites, install, database, running the checks —
is in the repository [`README.md`](../../README.md). Start there.

## Where things are documented

| Topic | Location |
|-------|----------|
| Local setup, install, database | [`README.md`](../../README.md) |
| Formatting, static analysis, the pre-PR check | [`../engineering/code-quality.md`](../engineering/code-quality.md) |
| Testing conventions | [`../engineering/testing.md`](../engineering/testing.md) |
| Migration & schema conventions | [`../engineering/database.md`](../engineering/database.md) |
| Code structure & abstraction rules | [`../engineering/architecture-guidelines.md`](../engineering/architecture-guidelines.md) |
| Branching & pull requests | [`../engineering/git-workflow.md`](../engineering/git-workflow.md) |

## Domain naming note

Nexo 2.0 uses **Vendor** in code, schema and UI (`vendors`, `vendor_id`), per
[ADR-0005](../architecture/adr/ADR-0005-vendor-naming.md) (recommended for acceptance in the
Phase 1 review). ADR-0002's `tenant` naming applied to the legacy SaaS database and is proposed
to be marked superseded.
