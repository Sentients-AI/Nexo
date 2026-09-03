# Architecture Documentation

This directory records the architectural boundaries, invariants, and decisions
that shape Nexo.

## Contents

| Path                        | Purpose                                                              |
|-----------------------------|--------------------------------------------------------------------|
| `INVARIANTS.md`             | Properties that must hold regardless of implementation             |
| `marketplace-migration.md`  | The plan for pivoting from multi-tenant SaaS to a single marketplace |
| `adr/`                      | Architecture Decision Records                                       |

## Architecture Decision Records

An ADR captures **one** boundary-shaping decision: why it was made, what was
rejected, and what must now be lived with. See
[`adr/0001-adr-template-and-process.md`](adr/0001-adr-template-and-process.md)
for when to write one and the template to use.

| ADR | Decision |
|-----|----------|
| [0001](adr/0001-adr-template-and-process.md) | ADR template and process |
| [0002](adr/0002-vendor-is-a-tenant-record.md) | A "vendor" is the existing tenant record, reframed |
| [0003](adr/0003-context-dependent-vendor-scoping.md) | Context-dependent vendor scoping (retire the blanket global scope) |
| [0004](adr/0004-order-group-and-per-vendor-orders.md) | One basket, one payment, one Order per vendor |

Engineering standards that do **not** shape a domain, data-isolation, money,
runtime-dependency, or deployment boundary live in
[`../engineering/`](../engineering/), not in an ADR.
