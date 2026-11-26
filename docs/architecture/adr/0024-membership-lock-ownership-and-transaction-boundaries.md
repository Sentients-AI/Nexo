# ADR-0024: Membership lock ownership and transaction boundaries

- **Number:** 0024
- **Status:** Proposed
- **Date:** 2026-11-26
- **Decision owner:** Project owner
- **Supersedes:** Only the caller-precondition sentence in [ADR-0011 Decision 10](0011-identity-roles-and-vendor-isolation.md#decision) that requires callers of `ActiveMembershipCheck` and membership use cases to already hold the user-row lock in the current transaction.

## Context

Issue #36 introduces audited membership and staff-role mutations. ADR-0011 gives the initiating application operation ownership of its transaction, while Decision 10's caller precondition requires a user lock on entry to membership use cases. Ownership changes among existing active members and terminal deactivation need a different lock subset from membership creation.

## Problem

Which boundary owns the transaction and locks for each membership and staff-role mutation?

## Decision drivers

Preserve staff/member exclusivity, exactly one active owner once assigned, audit atomicity, and a deterministic acquisition order.

## Considered alternatives

Requiring external callers to lock users for every membership operation complicates transaction and retry ownership and adds unnecessary locks to transfer and deactivation. Operation-specific lock subsets inside public application boundaries preserve the invariants.

## Decision

Public application operations acquire the required locks on the default connection within their transaction boundary. The existing global order specifies the relative acquisition order of locks an operation needs.

| Operation | Sequence |
|---|---|
| `AssignFirstOwner` | Exclusive user lock → exclusive vendor lock → current membership locking reads → validate authoritative eligibility and ownership → insert membership → audit |
| `TransferOwnership` and `ReassignOwner` | Exclusive vendor lock → exclusive locks on affected membership rows in ascending ID order → validate current ownership, active status and vendor consistency → demote the previous owner before promoting the replacement → audit |
| `DeactivateMember` | Exclusive vendor lock → exclusive target membership lock → revalidate identity, ownership and status → terminal deactivation preserving history → audit |
| `GrantPlatformRole` | Exclusive user lock → current locking read through `ActiveMembershipCheck` → refuse if an active membership exists → mutate platform role → audit |

Transfer, reassignment and deactivation omit user locks. Transfer and reassignment operate on existing active members within one vendor. They create no membership and make no platform-role change. Member-addressed operations retain the preliminary discovery and locked revalidation required by ADR-0011 Decision 10.

The caller of `ActiveMembershipCheck` already holds the relevant exclusive user-row lock within the active default-connection transaction. The port performs a current locking read under MySQL REPEATABLE READ. It owns no transaction, commit, rollback or retry. Internal transaction-bound operations retain any explicitly documented caller-held lock preconditions. Neither `LockedUser` nor an active transaction proves that the caller holds a particular lock.

The outermost transaction owner applies the three-attempt concurrency retry policy and reloads authoritative state each time. Nested operations run within the caller's transaction without independent retries. A nested caller must account for its existing locks and may acquire earlier-order locks only before acquiring later-order locks. MySQL error 1213 rolls back the entire transaction. Error 1205 ordinarily rolls back the failed statement under the existing server configuration. The outermost owner handles concurrency errors. Exhausted retries surface as unexpected failures. Each successful mutation and its audit entry commit or roll back together.

All other clauses of ADR-0011 Decision 10 and all other decisions remain authoritative.

## Positive consequences

Membership and staff-role assignment serialize on the same user row. Ownership operations and deactivation serialize on the vendor and relevant membership rows. Staff-role assignment and deactivation coordinate through the current membership locking read and the membership mutation.

## Negative consequences

Future operations that create an active membership or change staff/member exclusivity must explicitly address serialization on the user row.

## Risks

The lock order reduces conflicting acquisition patterns. MySQL can still detect a deadlock, and an outer transaction may already have rolled back when a nested operation surfaces error 1213.

## Testing implications

Preserve the approved #36 race outcome matrices and assert safety and progress against MySQL 8.4 using independent processes. Keep retry fault injection separate from real locking tests and verify audit atomicity.

## Migration implications

This decision changes no schema. The membership schema remains as specified in ADR-0011.

## Related

[ADR-0011](0011-identity-roles-and-vendor-isolation.md), [ADR-0023](0023-transactional-audit-record.md), [issue #36](https://github.com/Sentients-AI/Nexo/issues/36).
