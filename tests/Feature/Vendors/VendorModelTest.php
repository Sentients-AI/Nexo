<?php

declare(strict_types=1);

use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorStatus;
use Illuminate\Support\Facades\DB;

it('starts a new vendor as pending before it is saved', function (): void {
    expect((new Vendor)->status)->toBe(VendorStatus::Pending);
});

it('saves a vendor as pending', function (): void {
    $vendor = Vendor::create(['slug' => 'acme-supplies', 'name' => 'Acme Supplies']);

    expect($vendor->fresh()?->status)->toBe(VendorStatus::Pending)
        ->and(DB::table('vendors')->where('id', $vendor->id)->value('status'))->toBe('pending');
});

it('reads the stored status as a VendorStatus', function (VendorStatus $status): void {
    $id = DB::table('vendors')->insertGetId(['slug' => 'acme-supplies', 'name' => 'Acme Supplies', 'status' => $status->value]);

    expect(Vendor::findOrFail($id)->status)->toBe($status);
})->with(VendorStatus::cases());

it('lets only slug and name be mass assigned', function (): void {
    $vendor = new Vendor(['id' => 99, 'slug' => 'acme-supplies', 'name' => 'Acme Supplies', 'status' => 'approved']);

    expect($vendor->getFillable())->toBe(['slug', 'name'])
        ->and($vendor->id)->toBeNull()
        ->and($vendor->slug)->toBe('acme-supplies')
        ->and($vendor->name)->toBe('Acme Supplies')
        ->and($vendor->status)->toBe(VendorStatus::Pending);
});

it('stores timestamps with microseconds', function (): void {
    $vendor = Vendor::create(['slug' => 'acme-supplies', 'name' => 'Acme Supplies']);

    expect((string) DB::table('vendors')->where('id', $vendor->id)->value('created_at'))
        ->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/');
});

it('has no global scope', function (): void {
    // ADR-0003: vendor scoping is explicit, never a global scope.
    expect((new Vendor)->getGlobalScopes())->toBe([]);
});

it('lists exactly the statuses the database allows', function (): void {
    $clause = (string) DB::scalar(
        'SELECT check_clause FROM information_schema.check_constraints
         WHERE constraint_schema = DATABASE() AND constraint_name = ?',
        ['vendors_status_check'],
    );
    // information_schema escapes the quotes: (`status` in (_utf8mb4\'pending\',...)).
    preg_match_all("/'([^']*)'/", stripslashes($clause), $matches);

    expect($matches[1])->toEqualCanonicalizing(array_column(VendorStatus::cases(), 'value'));
});
