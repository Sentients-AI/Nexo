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
}
