<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Application;

use App\Modules\Vendors\Domain\InvalidVendorDetails;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * The audited way to create a Vendor (ADR-0023 Decision 6; INV-19; #35). In
 * one transaction it calls the unchanged CreateVendor, which validates and
 * translates a duplicate slug, then records `vendor.created`. The Vendor and
 * its audit record commit or roll back together.
 *
 * Only this class calls CreateVendor::handle() (dependency-rules.md L9).
 *
 * Transactions (ADR-0011 Decision 10). As the outermost transaction it owns
 * the transaction and retries a deadlock or lock-wait timeout up to
 * ATTEMPTS times in all; each attempt starts again from the input. Inside a
 * caller's transaction it runs in a savepoint and does not retry: a
 * concurrency error propagates to the caller as
 * Illuminate\Database\DeadlockException, and the owner of the outermost
 * transaction handles it and retries. Laravel attempts to roll back the
 * savepoint, but a real MySQL deadlock (1213) has already rolled back the
 * entire transaction, so the caller's transaction is not guaranteed to be
 * usable afterwards. A refusal (invalid details, duplicate slug) is never
 * retried.
 *
 * The actor is recorded as given. Accepting an Actor authenticates and
 * authorises nothing: callers must authorise first.
 */
final readonly class RegisterVendor
{
    public const ACTION = 'vendor.created';

    public const SUBJECT_TYPE = 'vendor';

    /** Attempts in all, when this is the outermost transaction. */
    public const ATTEMPTS = 3;

    public function __construct(
        private CreateVendor $createVendor,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  string  $name  The display name. Surrounding whitespace is trimmed.
     * @param  string  $slug  The public identifier, stored exactly as given.
     *
     * @throws InvalidVendorDetails when the name or slug breaks its rule; nothing is written
     * @throws VendorSlugAlreadyExists when another Vendor holds an equivalent slug; nothing is written
     */
    public function handle(Actor $actor, string $name, string $slug): Vendor
    {
        return DB::transaction(function () use ($actor, $name, $slug): Vendor {
            $vendor = $this->createVendor->handle($name, $slug);

            // The row as written, not the model: the database has the final say.
            $row = Vendor::query()->whereKey($vendor->id)->toBase()->first(['slug', 'name', 'status']);

            $this->audit->record($actor, self::ACTION, self::SUBJECT_TYPE, $vendor->id, null, [
                'slug' => $row?->slug,
                'name' => $row?->name,
                'status' => $row?->status,
            ]);

            return $vendor;
        }, self::ATTEMPTS);
    }
}
