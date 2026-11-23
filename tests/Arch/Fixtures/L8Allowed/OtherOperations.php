<?php

namespace Tests\Arch\Fixtures\L8Allowed;

use App\Modules\Vendors\Domain\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Not reported by L8. The first method creates no Vendor. The second holds
 * the rule's known blind spots, which it cannot see statically (documented
 * in VendorCreationRule). Analysed only, never executed.
 */
final class OtherOperations
{
    public function allowed(Vendor $vendor, OtherModel $other): ?Vendor
    {
        $vendor->name = 'Acme';
        $vendor->update(['name' => 'Acme']);
        $vendor->save();
        Vendor::query()->whereKey(1)->update(['name' => 'Acme']);
        DB::table('vendors')->where('id', 1)->update(['name' => 'Acme']);

        OtherModel::create(['name' => 'Acme']);
        new OtherModel;
        DB::table('users')->insert(['name' => 'Acme']);

        return Vendor::query()->where('slug', 'acme')->first();
    }

    public function blindSpots(string $table, string $class): void
    {
        app(Vendor::class);
        app()->make(Vendor::class);
        DB::table($table)->insert(['slug' => 'acme', 'name' => 'Acme']);
        DB::table('vendors as v')->insert(['slug' => 'acme', 'name' => 'Acme']);
        DB::insert("insert into vendors (slug, name) values ('acme', 'Acme')");
        DB::statement("insert into vendors (slug, name) values ('acme', 'Acme')");
        new $class;
    }
}

final class OtherModel extends Model {}
