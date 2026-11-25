<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Application;

use App\Modules\Vendors\Domain\InvalidVendorTransition;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorLifecycle;
use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The audited way to suspend a Vendor, approved → suspended (ADR-0023 Decisions 5
 * and 6; INV-19; #35). In one transaction it locks the vendors row and reads
 * `before` from it, calls the unchanged VendorLifecycle::suspend(), reads
 * `after` from the row as written, and records `vendor.suspended`. The change and
 * its audit record commit or roll back together; a refused transition
 * writes neither.
 *
 * The Vendor is named by id: no caller model is read or changed, so a stale
 * copy held by the caller cannot reach the snapshot. Only this class calls
 * VendorLifecycle::suspend() (dependency-rules.md L9).
 *
 * Transactions (ADR-0011 Decision 10). As the outermost transaction it owns
 * the transaction and retries a deadlock or lock-wait timeout up to
 * ATTEMPTS times in all; each attempt reloads and relocks the row and builds
 * a new model and new snapshots. Inside a caller's transaction it runs in a
 * savepoint and does not retry: a concurrency error rolls back the savepoint
 * and reaches the caller as Illuminate\Database\DeadlockException, for the
 * outermost transaction to retry. A refusal is never retried.
 *
 * The actor is recorded as given. Accepting an Actor authenticates and
 * authorises nothing: callers must authorise first.
 */
final readonly class SuspendVendor
{
    public const ACTION = 'vendor.suspended';

    public const SUBJECT_TYPE = 'vendor';

    /** Attempts in all, when this is the outermost transaction. */
    public const ATTEMPTS = 3;

    public function __construct(
        private VendorLifecycle $lifecycle,
        private AuditRecorder $audit,
    ) {}

    /**
     * @return Vendor the Vendor as written, loaded inside the transaction
     *
     * @throws InvalidVendorTransition when the locked row is not approved; nothing is written
     * @throws ModelNotFoundException<Vendor> when no Vendor has this id; nothing is written
     */
    public function handle(Actor $actor, int $vendorId): Vendor
    {
        return DB::transaction(function () use ($actor, $vendorId): Vendor {
            // A locking read: the latest committed row, held until the end.
            $vendor = Vendor::query()->whereKey($vendorId)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Vendor::class, [$vendorId]);

            $before = ['status' => $vendor->status->value];

            $this->lifecycle->suspend($vendor);

            $after = ['status' => Vendor::query()->whereKey($vendorId)->toBase()->value('status')];

            $this->audit->record($actor, self::ACTION, self::SUBJECT_TYPE, $vendorId, $before, $after);

            return $vendor;
        }, self::ATTEMPTS);
    }
}
