<?php

namespace App\Modules\Vendors\Domain;

/**
 * A Vendor's lifecycle state (vendor-domain-design.md §4). The vendors
 * table's vendors_status_check allows exactly these values.
 */
enum VendorStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Suspended = 'suspended';

    /**
     * Whether a Vendor in this status is eligible to operate: only an approved
     * one (vendor-domain-design.md §6, §8; Story #1). Approval is required but
     * not necessarily sufficient: a later marketplace condition is added to
     * Vendor::canOperate(), not here.
     */
    public function canOperate(): bool
    {
        return $this === self::Approved;
    }
}
