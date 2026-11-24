# ADR-0023: Record audited changes in an append-only audit log, in the same transaction, through explicit orchestrators

- **Number:** 0023
- **Status:** Proposed
- **Date:** 2026-11-24
- **Decision owner:** Project owner
- **Supersedes / relates to:** implements INV-19; relates to ADR-0010 (§2 lists the `Audit` recorder in the Shared kernel), ADR-0011 (Decision 12), ADR-0017 (outbox, Proposed), ADR-0021 (environments, Proposed)

---

## Context

- **INV-19** requires an audit record, in the same transaction as the change, with: actor, impersonator if any, action, subject, before and after, IP and timestamp. Its list includes vendor lifecycle changes and staff changes.
- **Further auditing by owner decision.** On 2026-11-24 the owner decided that Vendor creation (OD-5), membership changes and platform-role changes (OD-6) are audited too. `security-model.md` §7, a draft, lists more audited events. Those rows bind only once each is ratified.
- **The table's home.** ADR-0010 §2 places the `Audit` recorder in the Shared kernel, created when the first use case needs it. `domain-context-map.md` §2 makes Shared the owner of `audit_log`.
- **What exists today.** Story #1 shipped `CreateVendor` and `VendorLifecycle` without audit (`vendor-domain-design.md` §11, known limitations). Both run inside the caller's transaction if there is one, and neither opens a transaction of its own.
- **No authenticated identity exists.** The only operator path is the `vendors:create` console command.

## Problem

How does a change and its audit record commit or roll back together, with truthful before and after values and an honest actor, and how is a path that skips the audit detected?

## Decision drivers

- Atomicity: an audit record exists if and only if its change is committed.
- Truthfulness: before and after reflect the database, not a caller's copy.
- No false attribution: a console run is not an authenticated operator.
- Reuse: the Story #1 domain operations stay unchanged.
- Bypasses are detected statically where possible, and the blind spots are written down.

## Considered alternatives

### A. Audit from integration events or the outbox

Rejected. It records after the commit, not in the same transaction, and ADR-0017 is Proposed.

### B. Eloquent model observers

Rejected. `VendorLifecycle` changes status with a conditional query-builder update, which fires no model events, and observers make the audited path implicit.

### C. Database triggers

Rejected. A trigger cannot know the actor or the action's meaning.

### D. Explicit Application orchestrators that call the unchanged domain operation and a Shared recorder in one transaction, guarded by static rules (chosen)

## Decision

1. **`App\Shared\Audit`** holds:
   - `ActorType`: `user`, `system`, `console`.
   - `Actor`: readonly, with `type`, `userId?`, `impersonatorUserId?`, `label?`, `ip?`.
   - `AuditRecorder::record(Actor, action, subjectType, subjectId, before, after)`.
   - Shared imports no module.

2. **Provenance is not attribution.**
   - A `console` actor records which code path made the change: `label` holds the command name, for example `vendors:create`. It does not say who ran it, and console input never supplies an identity. There is no `--operator` option.
   - Only a `user` actor, built from an authenticated identity, attributes a change to a person. None exists until authentication is built.
   - `system` is for scheduled jobs and queue workers, with the job class as `label`.
   - Any audit view shows console rows as "unattributed (console)".

3. **Schema.** `audit_log` is owned by Shared and is append-only:

   ```php
   $table->id();
   $table->timestamp('occurred_at', 6);
   $table->string('actor_type', 16)->collation('utf8mb4_0900_bin');
   $table->unsignedBigInteger('actor_user_id')->nullable();         // no foreign key (OD-7)
   $table->unsignedBigInteger('impersonator_user_id')->nullable();  // no foreign key
   $table->string('actor_label', 191)->nullable();
   $table->string('action', 100)->collation('utf8mb4_0900_bin');    // e.g. vendor.approved
   $table->string('subject_type', 64)->collation('utf8mb4_0900_bin');
   $table->unsignedBigInteger('subject_id');
   $table->json('before')->nullable();
   $table->json('after')->nullable();
   $table->string('ip', 45)->nullable();
   $table->index(['subject_type', 'subject_id'], 'audit_log_subject_index');
   $table->index(['actor_user_id', 'occurred_at'], 'audit_log_actor_index');
   // CONSTRAINT audit_log_actor_type_check   CHECK (actor_type IN ('user','system','console'))
   // CONSTRAINT audit_log_actor_user_check   CHECK ((actor_type = 'user') = (actor_user_id IS NOT NULL))
   // CONSTRAINT audit_log_impersonator_check CHECK (impersonator_user_id IS NULL OR actor_type = 'user')
   ```

   - There is no `updated_at`.
   - The actor ids have no foreign key. Shared depends on no module's schema, and users are anonymised, not deleted.
   - The draft's `user_agent` column is deferred until an HTTP surface exists.

4. **One transaction, one connection.**
   - `AuditRecorder` uses the default connection. It throws `AuditOutsideTransaction` when that connection has no active transaction.
   - The business change and the audit insert run inside the same `DB::transaction` on the same connection object. A commit keeps both; a rollback discards both.
   - Nested inside a caller's transaction, the orchestrator uses a savepoint, and the outer commit decides. An audit record therefore exists if and only if its change is finally committed.
   - Orchestrators run no DDL, which commits implicitly in MySQL. Nothing outside the database happens inside the transaction.

5. **Authoritative snapshots (amendment B).**
   - An orchestrator loads the subject row with `lockForUpdate()` inside its transaction, and only then captures `before`.
   - `after` is read from the row as written, inside the same transaction.
   - A caller-supplied model is used only for its id.
   - Each retry starts from scratch: it reloads and relocks the row and never reuses a model mutated by a failed or rolled-back attempt.
   - Concurrency errors are retried only at the outermost transaction (ADR-0011 Decision 10).

6. **Audited orchestrators.**
   - `Vendors\Application\RegisterVendor`, `ApproveVendor`, `SuspendVendor` and `ReactivateVendor` each take an `Actor`.
   - Each one, inside one transaction:
     1. locks and reads the subject;
     2. calls the unchanged `CreateVendor` or `VendorLifecycle`;
     3. records the audit entry.
   - `vendors:create` calls `RegisterVendor` with a `console` actor.
   - Membership and platform-role use cases follow the same pattern.

7. **Bypass detection.**
   - **L9** (planned): outside the audited orchestrators, nothing calls `VendorLifecycle`'s transitions or `CreateVendor::handle`.
   - With **L4** (only `VendorLifecycle` writes a Vendor's status) and **L8** (only `CreateVendor` creates a Vendor), every status write and creation reaches the database only through an audited orchestrator.
   - The orchestrator tests prove that each orchestrator writes its record atomically. L9 proves there is no other caller.
   - These rules share the blind spots of L4 and L8: container resolution, raw SQL in variables, and code outside PHPStan's paths.

8. **Append-only is an application convention** (OD-8). It is not enforced by the database.
   - **L10** (planned) reports any statically identifiable mutation of `audit_log` anywhere in production code, **including `App\Shared\Audit`**:
     - `update`, `delete`, `truncate`, `upsert`, `updateOrInsert`, `updateOrCreate` and `increment`/`decrement` on a builder for `audit_log` or on an audit model;
     - `save`, `delete` and `forceDelete` on an audit model;
     - constant SQL passed to `DB::statement`, `DB::update`, `DB::delete` or `DB::unprepared` that updates, deletes, truncates or replaces `audit_log`.
   - The recorder's single insert is the only allowed write.
   - **Blind spots:** a table name or SQL held in a variable, container resolution, and code outside PHPStan's paths.
   - **Deferred:** database-level enforcement, for example revoking UPDATE and DELETE from the application's database user, belongs with deployment (ADR-0021).
   - **Separate approval required:** any retention or deletion mechanism.

## Positive consequences

- Audit is atomic with the change, by construction.
- Console changes are traceable to their command without pretending to identify a person.
- The Story #1 domain operations are reused unchanged.

## Negative consequences

- Every audited operation needs an orchestrator, and each new one must be added to L9's allowed callers.
- Append-only and bypass detection rely on static rules with stated blind spots.

## Risks

- **A new write path skips the orchestrator.** Mitigation: L4, L8 and L9, plus code review.
- **A test rolls back a change but leaves an audit row** (or the reverse). Mitigation: the atomicity tests below run on MySQL.

## Testing implications

All on MySQL (`nexo_testing`):

1. Inject a failure on the audit insert with `DB::beforeExecuting`: the business change rolls back.
2. A refused change (an invalid transition or duplicate slug) writes no audit row.
3. Rolling back the caller's outer transaction leaves neither row.
4. Rolling back to the savepoint inside an outer transaction discards both.
5. The recorder throws outside a transaction.
6. Two concurrent approvals of one vendor produce one change and exactly one audit row.
7. Inject a deadlock on the first attempt. The retry reloads the state, and exactly one audit row records the reloaded `before`.
8. Exhausted retries, by controlled fault injection: neither row is written and the concurrency error surfaces.
9. Each action's row has the expected actor, action, subject, before and after.
10. Raw-query tests for each CHECK constraint.
11. L9 and L10 fixtures with negative controls, and a fixture listing their blind spots.

## Migration implications

- A new `audit_log` table, owned by Shared.
- `vendors:create` switches from `CreateVendor` to `RegisterVendor`. Its output and exit codes are unchanged.

## Related

- INV-19; ADR-0010, ADR-0011, ADR-0017, ADR-0021; `security-model.md` §7 (draft); `vendor-domain-design.md` §11.
