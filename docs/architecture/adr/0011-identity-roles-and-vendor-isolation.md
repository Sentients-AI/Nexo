# ADR-0011: Model identity as one user table with vendor memberships and platform staff roles; enforce vendor isolation by context, policy and test

- **Number:** 0011
- **Status:** Accepted (owner, 2026-11-24; OD-1), after the revision made while Proposed on 2026-11-24 by owner decisions OD-1 to OD-18 and amendments A to E (see "Revision history"). Accepted is not implemented: no part of this decision is implemented yet.
- **Date:** 2026-11-01
- **Decision owner:** Project owner
- **Refines:** ADR-0003, which is still accepted: no global scope; explicit vendor scoping
- **Depends on:** ADR-0005 (Vendor naming; accepted 2026-11-22)
- **Relates to:** ADR-0010 (module contracts and ports), ADR-0020 (Filament for staff), ADR-0023 (transactional audit)

---

## Context

**Phase 0 finding S1 (critical).** In the old application, any registered shopper could reach `/vendor/*`. There were two causes:

- the vendor routes checked only `auth`;
- the tenant global scope failed open when no tenant was resolved.

ADR-0003 already chose explicit, context-dependent scoping over a global scope. The ratified personas (`docs/product/personas.md`) say:

- a vendor-team user belongs to exactly one vendor;
- an operator belongs to no vendor.

INV-2 states the same rule from the user's side: a user is a member of **at most one** vendor. A user with no active membership is not a vendor-team user.

The brief (§15) requires authorisation to be tested at the application boundary.

Story #1 delivered the Vendor's identity and lifecycle (`vendor-domain-design.md` §11). No authentication, Identity module, `platform_role`, membership or audit record exists yet. INV-1 is not implemented or verified; gate #30 tracks it.

## Problem

How are users, vendor teams and platform staff modelled? How is "this request acts for vendor X" established and enforced so that it cannot fail open?

## Decision drivers

- Default deny.
- A missing context must be an error, never "everything".
- Integrity rules that are structural are enforced by MySQL; rules that need history or several rows are enforced in one transaction, with a stated locking protocol.
- Simple, explicit and auditable.
- No dependency unless it removes real work.

## Considered alternatives

### A. `spatie/laravel-permission` with database-stored roles and permissions

- Flexible, and familiar to reviewers.
- Rejected for v2: roles here are a fixed, small, code-reviewed set. Database-editable permissions add a privilege-escalation surface and test burden with no product need.

### B. Separate guards and user tables for customers, vendors and staff

Rejected. A vendor owner is also a person who may shop, and duplicate accounts confuse the model.

### C. One `users` table; `vendor_members` for vendor roles; `users.platform_role` for staff; permissions as PHP enums mapped from roles; Laravel policies (chosen)

### Rejected refinements of C (2026-11-24 revision)

- **A plain `UNIQUE (user_id)` on `vendor_members`.** It makes a deactivated membership occupy the user's one vendor forever, so a former member can never join another vendor. Replaced by a unique active membership (Decision 2).
- **An application-maintained `owner_marker`.** It can drift from `role` and `status`. Replaced by a generated column.
- **Database triggers** for terminal deactivation and immutable foreign keys. Not used anywhere else in Nexo; replaced by conditional updates and a static write guard (L11).

## Decision

1. **One `users` table**, holding authentication and profile data. Every user can be a customer.

2. **Vendor membership** is `vendor_members`, owned by the Vendors module.
   - **Columns:** `id`, `vendor_id`, `user_id`, `role`, `status`, `deactivated_at`, and two stored generated columns, `active_marker` and `owner_marker` (definitions under "Migration implications").
   - **Roles:** `owner`, `manager`, `fulfilment`, `finance`, `support`.
   - **Statuses:** `active` and `deactivated`. Deactivation is **terminal**. A person who rejoins gets a new row, so history is kept.
   - **One active membership per user.** In v2 a user has at most one active membership, enforced by `UNIQUE (user_id, active_marker)`. Deactivated rows have a NULL marker and do not conflict.
   - **Owners:**
     - MySQL enforces **at most one** active owner per vendor with `UNIQUE (vendor_id, owner_marker)`.
     - "Exactly one" cannot be a database constraint. Once a vendor has had an owner, the application keeps exactly one: no use case may leave an owned vendor without an active owner.
     - A new vendor may be **unowned**. That is its bootstrap state. Only `AssignFirstOwner` takes a vendor out of it, and nothing returns a vendor to it.
     - `TransferOwnership` demotes the previous owner to `manager` and promotes the new owner in one transaction. It demotes first, because MySQL has no deferred constraints.
     - Operator reassignment is a separate, explicit boundary. It reuses the same transactional ownership mechanism.
   - **Vendor status:** a membership may be created for a `pending` or `approved` vendor, never for a `suspended` one.
   - **Changes:**
     - Every status or role change is a conditional update on the expected current value. This is a design rule; INV-14 is not extended to memberships in this milestone.
     - Deactivating a membership that is not active raises `MemberNotActive`.
   - `vendor_id` and `user_id` never change after insert. A static write guard (L11, planned in `dependency-rules.md` §7) limits membership writes to the membership use cases.

3. **Platform staff** are users with `platform_role ∈ {admin, operations, finance, support}`.
   - A staff user **may not** hold an active vendor membership, and staff accounts cannot place orders. Both are enforced in the application and asserted by tests. The membership side follows the locking protocol in Decision 10.
   - Staff accounts are created by an admin, never by registration. Two-factor authentication is mandatory for staff, delivered with staff authentication.
   - Granting or changing a platform role is the internal Identity use case `GrantPlatformRole`. It has no console command and no HTTP endpoint until staff authentication exists.

4. **Permissions** are an enum `Permission` (for example `vendor.orders.fulfil` or `platform.refunds.override`).
   - A static map in code assigns each role its permissions. The matrix is in `security-model.md`, which is a draft; each row is ratified when the first slice that uses it is planned.
   - The enum is introduced with the first policy, not before.
   - Policies check two things: (a) ownership, `resource.vendor_id === VendorContext.vendorId`, and (b) the role permission.

5. **VendorContext:**
   - **Resolution.** The middleware `vendor.context` on every `/vendor/*` route resolves the active membership of the authenticated user. It is resolved again on every request and is never taken from client input. A deactivation therefore takes effect on the next request.
   - **What it needs.** Resolving a context requires only an **active membership**. It does not require the vendor to be `approved`. Whether the vendor's status allows a given action is checked **per action**, from the suspended-vendor matrix in `vendor-domain-design.md` §8 (approved). A suspended vendor's members can still view history and fulfil existing orders.
   - **Refusal.** It responds **403** if there is no active membership, or if the user is platform staff.
   - **Value.** The resolved context is an immutable value object in `Vendors\Contracts`: `vendorId`, `memberId`, `userId`, `role`. It is injected into controllers and use cases.
   - **No fallback.** There is **no** global Eloquent scope and **no** null-means-all fallback. A vendor-scoped operation without a context is a programming error and throws `MissingVendorContext`.
   - **Trust boundary (amendment C).** A `VendorContext` is a data carrier, not a credential and not proof of authorisation.
     - Every vendor-scoped operation re-validates it against the database, inside its own transaction:
       1. the membership `memberId` exists, is `active`, and has the context's `vendorId`, `userId` and `role`;
       2. the vendor exists and its status allows the action.
     - Any mismatch raises `InvalidVendorContext` and changes nothing. That covers a fabricated context (no such membership), an inconsistent one (another vendor or user) and a stale one (deactivated, or the role changed).
   - **`ListVendorMembers(VendorContext)`** is an internal Application operation. It is not exposed through routes, controllers, Filament or any unauthenticated interface until an authenticated vendor surface exists.

6. **Vendor-owned queries:**
   - They use explicit `->whereBelongsToVendor($context)` query scopes, or receive `vendorId` as a required argument to an Application query.
   - Route-model binding for vendor routes is scoped. A vendor route binding `{order}` resolves only within the context's vendor; otherwise it returns 404, which avoids leaking existence.

7. **Platform admin** is Filament, behind a separate `/admin` panel. Access requires `platform_role`, and every Filament resource and action is checked by a policy.

8. **Impersonation:**
   - Only `admin` may impersonate, and never another staff member.
   - Each impersonation is audit-logged with start and stop.
   - Money-moving actions are blocked while impersonating.
   - Deferred to its own slice.

9. **Isolation test suite** (`tests/Feature/Isolation/`, gate #30).
   - For every vendor-facing route and action, it asserts that:
     - vendor A's member cannot read or mutate vendor B's records (404 or 403);
     - a customer without membership gets 403;
     - a deactivated membership gets 403;
     - a platform staff user gets 403.
   - An arch test fails if a vendor controller is not covered by the dataset. It lands in the same change as the first vendor controller. The suite is a merge gate.
   - **Staged applicability (owner decision OD-16).** #30 applies at the integration of each change that introduces vendor-owned data or vendor-facing access. Its criteria that can be tested without HTTP apply from the first vendor-owned table, `vendor_members`: database proofs, explicit scoping, ownership on creation, and Application-level cross-vendor denial. The HTTP 403 and 404 tests and the controller-coverage arch test become mandatory when the first HTTP surface exists. A criterion that does not apply yet is recorded as such on #30, never treated as met.

10. **Locking and transaction protocol (amendment A).**
    - **Ownership.** The Application use case that starts a business operation owns its transaction. Contracts and domain operations never begin, commit or roll back a transaction. All modules use the one default connection.
    - **Lock order.** Within a transaction, rows are locked in this order: `users` → `vendors` → `vendor_members` (ascending `id`) → audit insert last. An operation addressed by member id first reads the member's `vendor_id` without a lock, then locks in order, then re-checks the row under the lock.
    - **Locking reads.** A check made after taking a lock uses a locking read (`FOR UPDATE` or `FOR SHARE`). Under REPEATABLE READ a plain read sees the transaction's snapshot, which may predate a commit made while this transaction waited.
    - **Caller precondition.** `Identity\Contracts\Users::lockForUpdate()` acquires the user row lock. `ActiveMembershipCheck` and the membership use cases require, as a stated caller precondition, that the caller already holds that lock in the current transaction. An active transaction does not prove that a particular row is locked, and neither `TransactionRequired` nor the returned `LockedUser` value proves it. The protocol is verified by orchestration tests and concurrency tests, not by the type system.
    - **Retries.** Concurrency errors (deadlock 1213, lock-wait timeout 1205) are retried only at the outermost transaction. Each attempt reloads every row it needs and reuses no model mutated by a failed attempt. Domain refusals and unique-key violations are not retried.

11. **Identity boundary.**
    - Identity declares `Identity\Contracts\Users` (`exists`, `platformRole`, `lockForUpdate`), the `PlatformRole` enum, and the port `Identity\Contracts\ActiveMembershipCheck`.
    - Vendors implements the port and binds it in `VendorsServiceProvider` (Vendors `Infrastructure`), as ADR-0010 §4 requires for ports. Identity imports nothing from Vendors.
    - **Fail-closed.** If the port is not bound, resolving it throws and the staff-role grant writes nothing. If the check fails, its exception propagates and the transaction rolls back. Identity contains no default or null implementation of the port.
    - Other modules refer to users by `int` id. Only Identity touches `App\Models\User` and the `users` table.

12. **Audit.** Vendor creation, vendor lifecycle changes, membership changes (assignment, transfer, reassignment, role change, deactivation) and platform-role changes write an audit record in the same transaction as the change, as decided in ADR-0023.

## Positive consequences

- Fails closed by construction.
- Authorisation is reviewable in one matrix and one place per resource.
- History is kept: a deactivated membership is never deleted, and it does not block the person from joining another vendor later.
- The structural rules (one active membership per user, at most one owner) hold even for writes that bypass the application.

## Negative consequences

- Multi-vendor membership (one person running two stores) is not supported in v2. A later ADR would add a context switcher.
- Role changes require a code change. That is intended.
- "Exactly one owner", terminal deactivation, immutable foreign keys and staff exclusion depend on application code and its locking protocol, not on the database.

## Risks

- A developer forgets the vendor scope in a new query. Mitigation: scoped route binding, required `vendorId` arguments, context re-validation, the isolation dataset arch check, and code review.
- A use case breaks the lock order or reads without a lock, reopening a race. Mitigation: the protocol in Decision 10 and a concurrency proof for each race.
- The static guards (L9 to L11) cannot see container resolution, raw SQL or code outside PHPStan's paths.

## Testing implications

- **Isolation suite** (merge gate, staged as in Decision 9).
- **Database integrity:** raw-query tests for every constraint, asserting the MySQL error code and constraint name.
- **Concurrency (amendment E):**
  - Each race has a stated set of permitted successful and rejected outcomes. Tests assert the invariant **and** that every worker ends in one of those outcomes.
  - Tests do not assume exactly one winner unless that is the only permitted outcome.
  - Under the lock order, races must complete without exhausting retries. A worker that ends with exhausted retries fails the test: it is not evidence of correctness.
  - Exhausted retries are tested separately, by injecting a controlled concurrency fault.
- **Unit and feature tests:**
  - Policy unit tests per role × permission.
  - Staff-cannot-order and staff-cannot-join-vendor tests, including the concurrent case.
  - Negative tests for fabricated, inconsistent and stale vendor contexts.
  - Impersonation audit tests.

## Migration implications

- `users` (Identity) gains a nullable `platform_role`:

  ```php
  $table->string('platform_role', 32)->collation('utf8mb4_0900_bin')->nullable();
  // CONSTRAINT users_platform_role_check
  //   CHECK (platform_role IS NULL OR platform_role IN ('admin','operations','finance','support'))
  ```

- New `vendor_members` table (Vendors):

  ```php
  $table->id();
  $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete()->restrictOnUpdate();
  $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
  $table->string('role', 32)->collation('utf8mb4_0900_bin');
  $table->string('status', 32)->collation('utf8mb4_0900_bin')->default('active');
  $table->timestamp('deactivated_at', 6)->nullable();
  $table->unsignedTinyInteger('active_marker')->nullable()->storedAs("IF(`status` = 'active', 1, NULL)");
  $table->unsignedTinyInteger('owner_marker')->nullable()->storedAs("IF(`status` = 'active' AND `role` = 'owner', 1, NULL)");
  $table->timestamps(6);
  $table->unique(['user_id', 'active_marker'], 'vendor_members_one_active_per_user');
  $table->unique(['vendor_id', 'owner_marker'], 'vendor_members_one_active_owner');
  $table->index(['vendor_id', 'status'], 'vendor_members_vendor_status_index');
  // CONSTRAINT vendor_members_role_check         CHECK (role IN ('owner','manager','fulfilment','finance','support'))
  // CONSTRAINT vendor_members_status_check       CHECK (status IN ('active','deactivated'))
  // CONSTRAINT vendor_members_deactivation_check CHECK ((status = 'deactivated') = (deactivated_at IS NOT NULL))
  ```

  Notes:
  - A unique index treats NULLs as distinct.
  - Generated columns cannot be written directly (error 3105).
  - The binary `NO PAD` collation makes the CHECK constraints and generated expressions exact.
  - Each foreign key reuses the unique index that begins with its column. Those indexes cannot be dropped without first adding a replacement.

## Revision history

- **2026-11-24, revised while Proposed** (owner decisions OD-1 to OD-18, amendments A to E). Before acceptance; ADR-0006 fixes the body only after acceptance.
  - Decision 2: a unique **active** membership instead of a unique `user_id`; terminal deactivation; a generated owner marker; at most one owner in the database and exactly one in the application; the bootstrap state; transfer and reassignment; membership forbidden for suspended vendors.
  - Decision 3: 2FA wording; `GrantPlatformRole` internal only.
  - Decision 4: the enum arrives with the first policy.
  - Decision 5: context needs only an active membership, with per-action vendor eligibility; trust-boundary re-validation; `ListVendorMembers` internal only.
  - Decision 9: "deactivated" instead of "suspended" membership; staff gets 403; staged applicability.
  - New Decisions 10 to 12: the locking and transaction protocol; the Identity boundary and port; audit.
  - New sections: rejected refinements, testing implications for concurrency, migration definitions.

## Related

- ADR-0003, ADR-0005, ADR-0010, ADR-0020, ADR-0023; `security-model.md`; Phase 0 S1; gate #30.
