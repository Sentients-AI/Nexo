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

Per [ADR-0002](../architecture/adr/0002-vendor-is-a-tenant-record.md), the code
says `tenant` / `Tenant` / `tenant_id` where the product language says
**vendor**. They are the same concept. A future ADR decides whether to rename.
