<?php

namespace App\Modules\Vendors\Domain;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The Vendor lifecycle (vendor-domain-design.md §4): pending → approved →
 * suspended → approved. Each operation is one conditional UPDATE on the
 * expected current status (INV-14), so a stale model or a concurrent change
 * cannot succeed twice, and a rejected operation changes nothing. It runs on
 * the default connection, inside the caller's transaction if there is one.
 *
 * MySQL reports changed rows, not matched rows (no PDO::MYSQL_ATTR_FOUND_ROWS),
 * so "1 row" means this call changed the status. That holds because every
 * operation's target status differs from its source status.
 *
 * This is the only class that may write a Vendor's status
 * (dependency-rules.md L4).
 */
final class VendorLifecycle
{
    public function approve(Vendor $vendor): void
    {
        $this->move($vendor, 'approve', VendorStatus::Pending, VendorStatus::Approved);
    }

    public function suspend(Vendor $vendor): void
    {
        $this->move($vendor, 'suspend', VendorStatus::Approved, VendorStatus::Suspended);
    }

    public function reactivate(Vendor $vendor): void
    {
        $this->move($vendor, 'reactivate', VendorStatus::Suspended, VendorStatus::Approved);
    }

    /**
     * @throws InvalidVendorTransition when the persisted status is not $from
     * @throws ModelNotFoundException<Vendor> when the vendor was never saved or no longer exists
     */
    private function move(Vendor $vendor, string $operation, VendorStatus $from, VendorStatus $to): void
    {
        if ($vendor->getKey() === null) {
            throw (new ModelNotFoundException)->setModel(Vendor::class);
        }

        $id = (int) $vendor->getKey();
        $now = $vendor->freshTimestamp();

        $updated = Vendor::query()
            ->whereKey($id)
            ->where('status', $from->value)
            ->update(['status' => $to->value, 'updated_at' => $vendor->fromDateTime($now)]);

        if ($updated === 1) {
            $vendor->setAttribute('status', $to);
            $vendor->setAttribute('updated_at', $now);
            $vendor->syncOriginalAttributes(['status', 'updated_at']);

            return;
        }

        // Nothing changed: report what is actually stored, leaving $vendor as it was.
        // The base query returns the raw column; Eloquent's value() would cast it.
        $actual = Vendor::query()->whereKey($id)->toBase()->value('status');

        if ($actual === null) {
            throw (new ModelNotFoundException)->setModel(Vendor::class, [$id]);
        }

        throw InvalidVendorTransition::for($id, $operation, $from, VendorStatus::from((string) $actual));
    }
}
