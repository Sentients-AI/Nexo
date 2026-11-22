<?php

declare(strict_types=1);

use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorStatus;
use Database\Factories\VendorFactory;
use Illuminate\Support\Facades\DB;

it('is the factory the Vendor model resolves', function (): void {
    expect(Vendor::factory())->toBeInstanceOf(VendorFactory::class);
});

it('persists a pending vendor with a name and a URL-safe slug', function (): void {
    $vendor = Vendor::factory()->create()->fresh();

    expect($vendor?->status)->toBe(VendorStatus::Pending)
        ->and($vendor?->name)->not->toBe('')
        ->and($vendor?->slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
});

it('gives every vendor a unique slug', function (): void {
    Vendor::factory()->count(50)->create();

    expect(DB::table('vendors')->distinct()->count('slug'))->toBe(50);
});

it('takes explicit attributes, including the status', function (): void {
    $vendor = Vendor::factory()->create([
        'slug' => 'acme-supplies',
        'name' => 'Acme Supplies',
        'status' => VendorStatus::Suspended,
    ])->fresh();

    expect($vendor?->slug)->toBe('acme-supplies')
        ->and($vendor?->name)->toBe('Acme Supplies')
        ->and($vendor?->status)->toBe(VendorStatus::Suspended);
});

it('creates no user or other record', function (): void {
    Vendor::factory()->create();

    expect(DB::table('users')->count())->toBe(0)
        ->and(DB::table('vendors')->count())->toBe(1);
});
