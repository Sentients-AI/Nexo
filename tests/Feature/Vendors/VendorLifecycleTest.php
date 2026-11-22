<?php

declare(strict_types=1);

use App\Modules\Vendors\Domain\InvalidVendorTransition;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorLifecycle;
use App\Modules\Vendors\Domain\VendorStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * The Vendor lifecycle (vendor-domain-design.md §4): approve pending →
 * approved, suspend approved → suspended, reactivate suspended → approved.
 * Every other request fails and leaves the stored row as it was. Fixtures are
 * written with raw queries so each test starts from an exact stored state.
 */

const LIFECYCLE_EPOCH = '2026-01-01 00:00:00.000000';

/** Store a vendor in $status, with fixed timestamps, and load it. */
function storedVendor(VendorStatus $status): Vendor
{
    $id = DB::table('vendors')->insertGetId([
        'slug' => 'acme-supplies',
        'name' => 'Acme Supplies',
        'status' => $status->value,
        'created_at' => LIFECYCLE_EPOCH,
        'updated_at' => LIFECYCLE_EPOCH,
    ]);

    return Vendor::findOrFail($id);
}

/** @return array<string, mixed> */
function storedRow(int $id): array
{
    return (array) DB::table('vendors')->where('id', $id)->first();
}

it('performs each permitted transition and keeps the model in step with the row', function (string $operation, VendorStatus $from, VendorStatus $to): void {
    $vendor = storedVendor($from);
    $this->travelTo(Carbon::parse('2026-11-23 10:00:00.123456'));

    (new VendorLifecycle)->{$operation}($vendor);

    $row = storedRow($vendor->id);

    expect($row['status'])->toBe($to->value)
        ->and($row['updated_at'])->toBe('2026-11-23 10:00:00.123456')
        ->and($row['slug'])->toBe('acme-supplies')
        ->and($row['name'])->toBe('Acme Supplies')
        ->and($row['created_at'])->toBe(LIFECYCLE_EPOCH)
        ->and($vendor->status)->toBe($to)
        ->and($vendor->updated_at?->format('Y-m-d H:i:s.u'))->toBe('2026-11-23 10:00:00.123456')
        ->and($vendor->isDirty())->toBeFalse()
        ->and($vendor->fresh()?->status)->toBe($to);
})->with([
    'approve: pending → approved' => ['approve', VendorStatus::Pending, VendorStatus::Approved],
    'suspend: approved → suspended' => ['suspend', VendorStatus::Approved, VendorStatus::Suspended],
    'reactivate: suspended → approved' => ['reactivate', VendorStatus::Suspended, VendorStatus::Approved],
]);

it('rejects every other transition and changes nothing', function (string $operation, VendorStatus $required, VendorStatus $stored): void {
    $vendor = storedVendor($stored);
    $before = storedRow($vendor->id);

    try {
        (new VendorLifecycle)->{$operation}($vendor);
        $this->fail("{$operation} was expected to fail from {$stored->value}.");
    } catch (InvalidVendorTransition $exception) {
        expect($exception->vendorId)->toBe($vendor->id)
            ->and($exception->operation)->toBe($operation)
            ->and($exception->requiredStatus)->toBe($required)
            ->and($exception->actualStatus)->toBe($stored)
            ->and($exception->getMessage())->toBe("Cannot {$operation} vendor {$vendor->id}: its status is {$stored->value}, not {$required->value}.");
    }

    expect(storedRow($vendor->id))->toBe($before)
        ->and($vendor->status)->toBe($stored)
        ->and($vendor->isDirty())->toBeFalse();
})->with([
    'approve an approved vendor' => ['approve', VendorStatus::Pending, VendorStatus::Approved],
    'approve a suspended vendor' => ['approve', VendorStatus::Pending, VendorStatus::Suspended],
    'suspend a pending vendor' => ['suspend', VendorStatus::Approved, VendorStatus::Pending],
    'suspend a suspended vendor' => ['suspend', VendorStatus::Approved, VendorStatus::Suspended],
    'reactivate a pending vendor' => ['reactivate', VendorStatus::Suspended, VendorStatus::Pending],
    'reactivate an approved vendor' => ['reactivate', VendorStatus::Suspended, VendorStatus::Approved],
]);

it('offers only the three lifecycle operations, so no transition into pending exists', function (): void {
    $class = new ReflectionClass(VendorLifecycle::class);
    $public = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        $class->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($class->isFinal())->toBeTrue()
        ->and($public)->toEqualCanonicalizing(['approve', 'suspend', 'reactivate']);
});

it('rejects a repeated request on the same model', function (string $operation, VendorStatus $from, VendorStatus $to): void {
    $vendor = storedVendor($from);
    $lifecycle = new VendorLifecycle;

    $lifecycle->{$operation}($vendor);
    $afterFirst = storedRow($vendor->id);

    expect(fn () => $lifecycle->{$operation}($vendor))->toThrow(InvalidVendorTransition::class);
    expect(storedRow($vendor->id))->toBe($afterFirst)
        ->and($vendor->status)->toBe($to);
})->with([
    'approve twice' => ['approve', VendorStatus::Pending, VendorStatus::Approved],
    'suspend twice' => ['suspend', VendorStatus::Approved, VendorStatus::Suspended],
    'reactivate twice' => ['reactivate', VendorStatus::Suspended, VendorStatus::Approved],
]);

it('decides on the stored status, not a stale model, and reports it without changing the model', function (): void {
    $vendor = storedVendor(VendorStatus::Pending);
    DB::table('vendors')->where('id', $vendor->id)->update(['status' => 'approved']);

    try {
        (new VendorLifecycle)->approve($vendor);
        $this->fail('approve was expected to fail on a vendor already approved in the database.');
    } catch (InvalidVendorTransition $exception) {
        expect($exception->actualStatus)->toBe(VendorStatus::Approved);
    }

    expect($vendor->status)->toBe(VendorStatus::Pending)
        ->and($vendor->isDirty())->toBeFalse()
        ->and(storedRow($vendor->id)['status'])->toBe('approved');
});

it('applies an operation the stored status allows even when the model is stale', function (): void {
    $vendor = storedVendor(VendorStatus::Approved);
    DB::table('vendors')->where('id', $vendor->id)->update(['status' => 'pending']);

    (new VendorLifecycle)->approve($vendor);

    expect(storedRow($vendor->id)['status'])->toBe('approved')
        ->and($vendor->status)->toBe(VendorStatus::Approved);
});

it('reports a deleted vendor as missing, not as an invalid transition', function (string $operation): void {
    $vendor = storedVendor(VendorStatus::Pending);
    DB::table('vendors')->where('id', $vendor->id)->delete();

    expect(fn () => (new VendorLifecycle)->{$operation}($vendor))
        ->toThrow(ModelNotFoundException::class, 'No query results for model ['.Vendor::class."] {$vendor->id}");
    expect(DB::table('vendors')->count())->toBe(0);
})->with(['approve', 'suspend', 'reactivate']);

it('reports an unsaved vendor as missing', function (): void {
    expect(fn () => (new VendorLifecycle)->approve(new Vendor(['slug' => 'acme-supplies', 'name' => 'Acme Supplies'])))
        ->toThrow(ModelNotFoundException::class);
    expect(DB::table('vendors')->count())->toBe(0);
});

it('rolls back with the caller\'s transaction', function (): void {
    $vendor = storedVendor(VendorStatus::Pending);
    $before = storedRow($vendor->id);

    try {
        DB::transaction(function () use ($vendor): void {
            (new VendorLifecycle)->approve($vendor);
            expect(storedRow($vendor->id)['status'])->toBe('approved');

            throw new RuntimeException('a later step failed');
        });
    } catch (RuntimeException) {
        // The caller's transaction is rolled back; the lifecycle change with it.
    }

    expect(storedRow($vendor->id))->toBe($before);
});

it('commits several transitions within one caller transaction', function (): void {
    $vendor = storedVendor(VendorStatus::Pending);
    $lifecycle = new VendorLifecycle;

    DB::transaction(function () use ($lifecycle, $vendor): void {
        $lifecycle->approve($vendor);
        $lifecycle->suspend($vendor);
        $lifecycle->reactivate($vendor);
    });

    expect(storedRow($vendor->id)['status'])->toBe('approved')
        ->and($vendor->status)->toBe(VendorStatus::Approved);
});
