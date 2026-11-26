<?php

use App\Modules\Vendors\Domain\VendorMember;
use Illuminate\Support\Facades\DB;

function memberWrites(VendorMember $member): void
{
    $member->role = 'owner'; // violation
    $member['user_id'] = 8; // violation
    $member->setAttribute('vendor_id', 10); // violation
    $member->save(); // violation
    VendorMember::forceCreate(['vendor_id' => 1, 'user_id' => 2]); // violation
    VendorMember::query()->whereKey(1)->update(['status' => 'active']); // violation
    DB::table('vendor_members')->where('id', 1)->update(['role' => 'manager']); // violation
    DB::table('vendor_members')->delete(); // violation
    VendorMember::query()->where('vendor_id', 1)->first();
    DB::table('users')->where('id', 1)->update(['role' => 'manager']);
}
