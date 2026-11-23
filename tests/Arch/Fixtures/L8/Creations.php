<?php

namespace Tests\Arch\Fixtures\L8;

use App\Modules\Vendors\Domain\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Violates L8: a Vendor created outside CreateVendor. Analysed by
 * VendorCreationRuleTest only, never executed. Each reported line is marked.
 */
final class Creations
{
    /**
     * @param  class-string<Vendor>  $class
     */
    public function create(Vendor $vendor, ?Vendor $maybe, string $class): void
    {
        new Vendor; // violation
        new Vendor(['slug' => 'acme', 'name' => 'Acme']); // violation
        new $class; // violation
        new class extends Vendor {}; // violation

        Vendor::create(['slug' => 'acme', 'name' => 'Acme']); // violation
        Vendor::forceCreate(['slug' => 'acme', 'name' => 'Acme']); // violation
        Vendor::make(['slug' => 'acme', 'name' => 'Acme']); // violation
        Vendor::firstOrCreate(['slug' => 'acme'], ['name' => 'Acme']); // violation
        Vendor::firstOrNew(['slug' => 'acme']); // violation
        Vendor::updateOrCreate(['slug' => 'acme'], ['name' => 'Acme']); // violation
        Vendor::createOrFirst(['slug' => 'acme'], ['name' => 'Acme']); // violation
        Vendor::insert(['slug' => 'acme', 'name' => 'Acme']); // violation
        Vendor::upsert([['slug' => 'acme', 'name' => 'Acme']], ['slug']); // violation
        Vendor::factory(); // violation

        Vendor::query()->create(['slug' => 'acme', 'name' => 'Acme']); // violation
        Vendor::query()->createQuietly(['slug' => 'acme', 'name' => 'Acme']); // violation
        Vendor::where('id', 1)->firstOrCreate(['slug' => 'acme']); // violation
        Vendor::query()->newModelInstance(['slug' => 'acme']); // violation
        Vendor::query()->fillAndInsert([['slug' => 'acme', 'name' => 'Acme']]); // violation
        Vendor::query()->toBase()->insertGetId(['slug' => 'acme', 'name' => 'Acme']); // violation

        $vendor->create(['slug' => 'acme', 'name' => 'Acme']); // violation
        $maybe?->replicate(); // violation
        $vendor->newInstance(['slug' => 'acme']); // violation

        DB::table('vendors')->insert(['slug' => 'acme', 'name' => 'Acme']); // violation
        DB::table('vendors')->insertOrIgnore(['slug' => 'acme', 'name' => 'Acme']); // violation
        DB::connection()->table('vendors')->updateOrInsert(['slug' => 'acme'], ['name' => 'Acme']); // violation
    }
}
