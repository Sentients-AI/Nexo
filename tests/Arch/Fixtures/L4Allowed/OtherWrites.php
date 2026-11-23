<?php

namespace Tests\Arch\Fixtures\L4Allowed;

use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Not reported by L4. The first method writes nothing it constrains. The
 * second holds the rule's known blind spots, which it cannot see statically
 * (documented in VendorStatusWriteRule). Analysed only, never executed.
 */
final class OtherWrites
{
    public function allowed(Vendor $vendor, OtherModel $other): VendorStatus
    {
        $vendor->name = 'Acme';
        $vendor->setAttribute('name', 'Acme');
        $vendor->update(['name' => 'Acme']);
        Vendor::query()->whereKey(1)->update(['name' => 'Acme']);
        DB::table('vendors')->update(['name' => 'Acme']);

        $other->status = 'active';
        $other->update(['status' => 'active']);
        DB::table('users')->update(['status' => 'active']);

        $array = ['status' => 'approved'];
        $array['status'] = 'suspended';

        return $vendor->status;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function blindSpots(Vendor $vendor, array $values, string $column, string $table): void
    {
        $vendor->update($values);
        $vendor->{$column} = 'approved';
        DB::table($table)->update(['status' => 'approved']);
        DB::update("update vendors set status = 'approved'");
        DB::statement("update vendors set status = 'approved'");
    }
}

final class OtherModel extends Model {}
