<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * What other modules may know about a user (ADR-0011 Decision 11): whether
 * the user exists and their platform role, by id. Identity owns the `users`
 * table; no other module reads it.
 *
 * Transactions belong to the caller (ADR-0011 Decision 10). No method begins,
 * commits, rolls back or retries one. A missing user is an error, never "a
 * user without a role".
 */
interface Users
{
    public function exists(int $userId): bool;

    /**
     * The user's platform role, or null for a user who is not staff. A plain
     * read: inside a transaction it may be a snapshot older than the latest
     * commit, so a decision that must hold until commit uses lockForUpdate().
     *
     * @throws UserNotFound
     */
    public function platformRole(int $userId): ?PlatformRole;

    /**
     * Locks the user's row (SELECT … FOR UPDATE) on the default connection,
     * inside the caller's transaction, and returns the row's current committed
     * values. Another transaction that locks the same row waits until this
     * one commits or rolls back.
     *
     * The caller must have begun the transaction. The returned LockedUser
     * records what was read under the lock; it does not prove that the lock
     * is still held. Code that relies on the lock (ADR-0011 Decision 10)
     * calls this first, in the same transaction, and is proven by concurrency
     * tests, not by this type.
     *
     * @throws TransactionRequired when the default connection has no open transaction
     * @throws UserNotFound
     */
    public function lockForUpdate(int $userId): LockedUser;
}
