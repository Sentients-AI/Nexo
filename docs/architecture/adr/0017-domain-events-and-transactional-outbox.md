# ADR-0017: Publish integration events through a transactional outbox with idempotent consumers

- **Number:** 0017
- **Status:** Proposed
- **Date:** 2026-11-01
- **Decision owner:** Project owner

---

## Context

Phase 0 finding C2: the old `DomainEventRecorder` wrote a `domain_events` table that nothing ever read. Meanwhile, plain Laravel events were dispatched before transactions committed in places. Either way, reactions could be lost or could happen for rolled-back work.

Nexo needs reliable reactions to state changes:

- ledger postings;
- notifications;
- search indexing;
- loyalty awards;
- payable maturity.

## Problem

How do modules react to each other's state changes reliably, without distributed transactions?

## Decision drivers

- An event is published **if and only if** the state change commits.
- At-least-once delivery, with consumers safe under duplicates.
- Observable: backlog, failures and dead letters are visible.

## Considered alternatives

### A. Laravel events with `ShouldDispatchAfterCommit` / `afterCommit` queued listeners

- Simple.
- Rejected as the *only* mechanism: if the process crashes between commit and dispatch, the reaction is lost, and there is no durable backlog to inspect or replay.

### B. External broker (Kafka/RabbitMQ)

Rejected. Unjustified operational weight for a modular monolith.

### C. Outbox table written in the same transaction, relayed to the queue; consumer inbox for de-duplication (chosen)

## Decision

1. **Outbox table:** `outbox_messages(id ULID, type, aggregate_type, aggregate_id, payload JSON, occurred_at, dispatched_at NULL, attempts, last_error)`.
   - It is written by `Shared\Outbox::record()` **inside** the use case's transaction.
   - Payloads are versioned DTOs from the publishing module's `Contracts/Events`.
2. **Relay:** a scheduled command (every 5 seconds, `withoutOverlapping`) selects undispatched rows `FOR UPDATE SKIP LOCKED` in batches.
   - It pushes one queue job per subscriber and sets `dispatched_at`.
   - Failures increment `attempts` with backoff.
   - After 10 attempts the row is marked dead and an operations alert is raised.
3. **Subscribers** are registered explicitly per event type in each consuming module's service provider. There is no auto-discovery.
4. **Inbox:** `processed_messages(consumer, message_id, processed_at)`, with a UNIQUE pair.
   - The consumer inserts its inbox row in the same transaction as its own effects.
   - A duplicate becomes a no-op.
   - Consumers that are naturally idempotent, such as ledger postings with unique `(type, reference)`, still use the inbox for uniformity.
5. **Ordering:** no global ordering guarantee. Consumers must tolerate reordering, by checking the state they act on, or by re-reading through the owner's contract.
6. **Intra-module** reactions that must be atomic stay inside the use case (same transaction). The outbox is for cross-module, eventually consistent reactions.
7. **Observability:** Filament shows outbox backlog, dead letters (with retry action) and consumer failures. The health endpoint reports the age of the oldest undispatched message.
8. **Retention:** dispatched rows are pruned after 14 days. Inbox rows are pruned after 30 days.

## Positive consequences

- No lost or phantom reactions.
- Replayable.
- Testable with a synchronous relay in tests.

## Negative consequences

- Up to about 5 seconds of extra latency on reactions.
- Two more tables and a scheduler dependency.

## Risks

- Scheduler not running in an environment. Mitigation: health check and alert on outbox age.

## Testing implications

- Rollback test: a rolled-back transaction publishes nothing.
- Crash simulation: a message is not dispatched until the relay runs.
- Duplicate delivery to every consumer is a no-op.
- The `SKIP LOCKED` relay runs in parallel without double dispatch (MySQL test).

## Migration implications

- `jobs`/`failed_jobs` from `main` stay (queue).
- New outbox and inbox tables.

## Related

- ADR-0010, ADR-0015, ADR-0016; Phase 0 C2.
