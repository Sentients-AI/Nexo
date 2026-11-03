# Architecture Documentation

This directory records Nexo's architectural boundaries, invariants and decisions.

## Contents

| Path | Purpose |
|---|---|
| [`system-overview.md`](system-overview.md) | Runtime components, code organisation, key flows |
| [`domain-context-map.md`](domain-context-map.md) | Modules (bounded contexts), owned data, relationships, glossary |
| [`dependency-rules.md`](dependency-rules.md) | Allowed and forbidden dependencies, enforced by arch tests |
| [`database-design.md`](database-design.md) | Initial database model, constraints and table ownership |
| [`INVARIANTS.md`](INVARIANTS.md) | Business invariants [INV-n], each with enforcement and proof |
| [`security-model.md`](security-model.md) | Roles, permission matrix, isolation and enforcement points |
| [`integration-contracts.md`](integration-contracts.md) | Browser ↔ Laravel, module ↔ module, and Nexo ↔ Stripe contracts |
| [`financial-model.md`](financial-model.md) | Ledger, commission, refunds, disputes, payouts, reconciliation, worked examples |
| [`lifecycles/`](lifecycles/) | Checkout and payment, inventory, orders/fulfilment/returns: state machines and failure-scenario matrices |
| [`vendor-domain-design.md`](vendor-domain-design.md) | Vendor entity design (2026-09), still current |
| [`marketplace-migration.md`](marketplace-migration.md) | *Superseded:* SaaS-to-marketplace migration plan |
| [`adr/`](adr/) | Architecture Decision Records |

## Architecture Decision Records

The template is [`adr/ADR-TEMPLATE.md`](adr/ADR-TEMPLATE.md) (ADR-0006). Proposed ADRs are not implemented until the owner accepts them.

| ADR | Decision | Status |
|---|---|---|
| [0001](adr/0001-adr-template-and-process.md) | ADR template and process | Accepted; template superseded by 0006 |
| [0002](adr/0002-vendor-is-a-tenant-record.md) | A vendor is the existing tenant record | Accepted. **Proposed to be marked Superseded by 0005** (fresh database) |
| [0003](adr/0003-context-dependent-vendor-scoping.md) | Context-dependent vendor scoping | Accepted; refined by 0011 |
| [0004](adr/0004-order-group-and-per-vendor-orders.md) | One basket, one payment, one order per vendor | Accepted. **Superseded by 0012 on acceptance** |
| [0005](adr/ADR-0005-vendor-naming.md) | Use Vendor as the seller entity | Proposed. **Recommended: accept** (file to be renamed `0005-vendor-naming.md`) |
| [0006](adr/0006-adr-process-v2.md) | Nexo 2.0 ADR template and statuses | Accepted (brief) |
| [0007](adr/0007-product-positioning-currency-and-locales.md) | Portfolio marketplace; MYR; EN primary with AR/MS | Accepted (D-4, D-5) |
| [0008](adr/0008-repository-and-frontend-stack.md) | This repository; Vue 3 + Inertia + TS | Accepted (D-1, D-2) |
| [0009](adr/0009-runtime-and-persistence-baseline.md) | Laravel 13 / PHP 8.5; MySQL 8.4 everywhere, including tests | Proposed (D-3) |
| [0010](adr/0010-modular-monolith-and-dependency-rules.md) | Modular monolith with enforced contracts | Proposed |
| [0011](adr/0011-identity-roles-and-vendor-isolation.md) | Identity, roles and fail-closed vendor isolation | Proposed |
| [0012](adr/0012-order-group-and-vendor-orders.md) | OrderGroup + VendorOrder state machines | Proposed |
| [0013](adr/0013-money-representation-and-allocation.md) | Money: integer minor units, deterministic allocation | Proposed |
| [0014](adr/0014-inventory-reservations.md) | Inventory: on-hand/reserved, TTL reservations, movement journal | Proposed |
| [0015](adr/0015-checkout-and-payment-orchestration.md) | Checkout saga, idempotency, webhook-driven payment | Proposed |
| [0016](adr/0016-financial-ledger-and-simulated-payouts.md) | Double-entry ledger; simulated payouts | Proposed (core approved as D-6; D-9/10/11 pending) |
| [0017](adr/0017-domain-events-and-transactional-outbox.md) | Transactional outbox and idempotent consumers | Proposed |
| [0018](adr/0018-backend-frontend-contracts.md) | PHP data objects → generated TS types | Proposed |
| [0019](adr/0019-search-behind-a-port.md) | Search port, MySQL first | Proposed (D-8) |
| [0020](adr/0020-platform-administration-in-filament.md) | Filament for staff; Vue for vendors and customers | Accepted (owner, 2026-11-04) |
| [0021](adr/0021-environments-demo-data-and-production-isolation.md) | Isolated environments; reproducible demo | Proposed |

Engineering standards that do **not** shape a domain, data-isolation, money, runtime-dependency or deployment boundary live in [`../engineering/`](../engineering/), not in an ADR.
