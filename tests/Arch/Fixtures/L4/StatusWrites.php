<?php

namespace Tests\Arch\Fixtures\L4;

use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorStatus;
use Illuminate\Support\Facades\DB;

/**
 * Violates L4: a Vendor's status written outside VendorLifecycle. Analysed by
 * VendorStatusWriteRuleTest only, never executed. Each reported line is marked.
 */
final class StatusWrites
{
    public function write(Vendor $vendor, ?Vendor $maybe): void
    {
        $vendor->status = VendorStatus::Approved; // violation
        $vendor['status'] = 'approved'; // violation
        $vendor->status ??= VendorStatus::Approved; // violation
        $vendor->setAttribute('status', 'approved'); // violation
        $vendor->fill(['status' => 'approved']); // violation
        $vendor->forceFill(['name' => 'Acme', 'status' => 'approved']); // violation
        $vendor->update(['status' => 'approved']); // violation
        $maybe?->update(['status' => 'approved']); // violation

        $values = ['status' => 'suspended'];
        $vendor->update($values); // violation

        Vendor::query()->whereKey(1)->update(['status' => 'approved']); // violation
        Vendor::where('id', 1)->update(['status' => 'approved']); // violation
        Vendor::query()->toBase()->update(['status' => 'approved']); // violation
        Vendor::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'approved']); // violation
        Vendor::forceCreate(['slug' => 'acme', 'name' => 'Acme', 'status' => 'approved']); // violation
        Vendor::query()->upsert([['slug' => 'acme', 'name' => 'Acme', 'status' => 'approved']], ['slug']); // violation

        DB::table('vendors')->where('id', 1)->update(['status' => 'approved']); // violation
        DB::table('vendors')->insert(['slug' => 'acme', 'name' => 'Acme', 'status' => 'approved']); // violation
        DB::connection()->table('vendors')->updateOrInsert(['id' => 1], ['status' => 'approved']); // violation
    }
}
