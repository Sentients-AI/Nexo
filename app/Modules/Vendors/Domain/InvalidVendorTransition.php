<?php

namespace App\Modules\Vendors\Domain;

use DomainException;

/**
 * A lifecycle operation was asked of a Vendor whose persisted status does not
 * allow it (vendor-domain-design.md §4). Nothing was changed. The message
 * names only the vendor id and statuses, never its name or slug.
 */
final class InvalidVendorTransition extends DomainException
{
    private function __construct(
        public readonly int $vendorId,
        public readonly string $operation,
        public readonly VendorStatus $requiredStatus,
        public readonly VendorStatus $actualStatus,
    ) {
        parent::__construct(sprintf(
            'Cannot %s vendor %d: its status is %s, not %s.',
            $operation,
            $vendorId,
            $actualStatus->value,
            $requiredStatus->value,
        ));
    }

    public static function for(int $vendorId, string $operation, VendorStatus $requiredStatus, VendorStatus $actualStatus): self
    {
        return new self($vendorId, $operation, $requiredStatus, $actualStatus);
    }
}
