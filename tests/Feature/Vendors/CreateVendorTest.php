<?php

declare(strict_types=1);

use App\Modules\Vendors\Application\CreateVendor;
use App\Modules\Vendors\Domain\InvalidVendorDetails;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use App\Modules\Vendors\Domain\VendorStatus;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * Story #1 Task 4 (#29): CreateVendor is the only way to create a Vendor. It
 * creates it pending, validates the name and slug (D1), and turns only the
 * slug's unique-constraint violation into VendorSlugAlreadyExists (D2).
 */

function createVendor(string $name = 'Acme Supplies', string $slug = 'acme-supplies'): Vendor
{
    return (new CreateVendor)->handle($name, $slug);
}

/** Stores a vendor directly, bypassing the use case, like a legacy row would. */
function storeVendorRow(string $slug): int
{
    return DB::table('vendors')->insertGetId([
        'slug' => $slug,
        'name' => 'Existing Vendor',
        'created_at' => '2026-01-01 00:00:00.000000',
        'updated_at' => '2026-01-01 00:00:00.000000',
    ]);
}

it('creates a pending vendor that cannot operate yet', function (): void {
    $vendor = createVendor();
    $row = DB::table('vendors')->where('id', $vendor->id)->first();

    expect($vendor->exists)->toBeTrue()
        ->and($vendor->id)->toBeInt()->toBeGreaterThan(0)
        ->and($vendor->slug)->toBe('acme-supplies')
        ->and($vendor->name)->toBe('Acme Supplies')
        ->and($vendor->status)->toBe(VendorStatus::Pending)
        ->and($vendor->canOperate())->toBeFalse()
        ->and($row?->slug)->toBe('acme-supplies')
        ->and($row?->name)->toBe('Acme Supplies')
        ->and($row?->status)->toBe('pending');
});

it('trims the name but stores the slug exactly as given', function (): void {
    $vendor = createVendor("  Acme Supplies\t\n", 'acme-supplies');

    expect(DB::table('vendors')->where('id', $vendor->id)->value('name'))->toBe('Acme Supplies');
});

it('accepts every slug the rule allows', function (string $slug): void {
    expect(createVendor(slug: $slug)->slug)->toBe($slug);
})->with([
    'three characters' => ['abc'],
    'eighty characters' => [str_repeat('a', 80)],
    'digits only' => ['123'],
    'single inner hyphens' => ['a1-b2-c3'],
]);

it('rejects a slug that breaks the rule and writes nothing', function (string $slug): void {
    expect(fn () => createVendor(slug: $slug))->toThrow(function (InvalidVendorDetails $e): void {
        expect($e->field)->toBe('slug');
    });

    expect(DB::table('vendors')->count())->toBe(0);
})->with([
    'empty' => [''],
    'two characters' => ['ab'],
    'eighty-one characters' => [str_repeat('a', 81)],
    'uppercase' => ['Acme-Supplies'],
    'accented' => ['acmé-supplies'],
    'underscore' => ['acme_supplies'],
    'space' => ['acme supplies'],
    'leading hyphen' => ['-acme'],
    'trailing hyphen' => ['acme-'],
    'consecutive hyphens' => ['acme--supplies'],
    'surrounding whitespace' => [' acme '],
    'trailing newline' => ["acme\n"],
    'only hyphens' => ['---'],
]);

it('accepts a name of exactly the maximum length in characters', function (): void {
    $name = str_repeat('é', CreateVendor::NAME_MAX_LENGTH);

    expect(createVendor(name: $name)->name)->toBe($name);
});

it('rejects a name that breaks the rule and writes nothing', function (string $name): void {
    expect(fn () => createVendor(name: $name))->toThrow(function (InvalidVendorDetails $e): void {
        expect($e->field)->toBe('name')
            ->and($e->getMessage())->toContain('1 to 255 characters');
    });

    expect(DB::table('vendors')->count())->toBe(0);
})->with([
    'empty' => [''],
    'whitespace only' => [" \t\n "],
    'one character too long' => [str_repeat('a', CreateVendor::NAME_MAX_LENGTH + 1)],
    'invalid UTF-8' => ["Acme \xFF"],
]);

it('rejects a duplicate slug, keeping the database error, and leaves the existing vendor unchanged', function (): void {
    $existing = createVendor('Acme Supplies', 'acme-supplies');

    expect(fn () => createVendor('Another Acme', 'acme-supplies'))
        ->toThrow(function (VendorSlugAlreadyExists $e): void {
            expect($e->slug)->toBe('acme-supplies')
                ->and($e->getMessage())->toBe('A vendor with this slug already exists.')
                ->and($e->getPrevious())->toBeInstanceOf(UniqueConstraintViolationException::class)
                ->and($e->getPrevious()?->index)->toBe('vendors_slug_unique');
        });

    expect(DB::table('vendors')->count())->toBe(1)
        ->and(DB::table('vendors')->where('id', $existing->id)->value('name'))->toBe('Acme Supplies');
});

it('rejects a slug equivalent to a stored one in case or accents', function (string $stored): void {
    storeVendorRow($stored);

    expect(fn () => createVendor(slug: 'acme-supplies'))->toThrow(VendorSlugAlreadyExists::class);
    expect(DB::table('vendors')->count())->toBe(1);
})->with([
    'case' => ['Acme-Supplies'],
    'accents' => ['acmé-supplies'],
]);

it('allows two vendors with the same name', function (): void {
    createVendor('Acme Supplies', 'acme-supplies');
    createVendor('Acme Supplies', 'acme-supplies-2');

    expect(DB::table('vendors')->where('name', 'Acme Supplies')->count())->toBe(2);
});

it('lets a unique-constraint violation on any other index propagate unchanged', function (): void {
    $other = new UniqueConstraintViolationException(
        'mysql',
        'insert into `vendors` ...',
        [],
        new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'vendors.vendors_other_unique'"),
    );
    $other->setIndex('vendors_other_unique');

    Vendor::creating(fn () => throw $other);

    expect(fn () => createVendor())->toThrow(fn (UniqueConstraintViolationException $e) => expect($e)->toBe($other));
    expect(DB::table('vendors')->count())->toBe(0);
});

it('lets any other database error propagate unchanged', function (): void {
    // A real MySQL error: the insert is rejected for a reason other than a duplicate slug.
    Vendor::creating(function (Vendor $vendor): void {
        $vendor->setAttribute('name', str_repeat('a', CreateVendor::NAME_MAX_LENGTH + 1));
    });

    expect(fn () => createVendor())->toThrow(function (QueryException $e): void {
        expect($e)->not->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($e->getMessage())->toContain('Data too long');
    });
    expect(DB::table('vendors')->count())->toBe(0);
});

it('rolls back with the caller\'s transaction', function (): void {
    try {
        DB::transaction(function (): void {
            createVendor();

            throw new RuntimeException('caller fails');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('vendors')->count())->toBe(0);
});

it('leaves the caller\'s transaction usable after a duplicate slug', function (): void {
    createVendor(slug: 'acme-supplies');

    DB::transaction(function (): void {
        try {
            createVendor(slug: 'acme-supplies');
        } catch (VendorSlugAlreadyExists) {
        }

        createVendor(slug: 'acme-supplies-2');
    });

    expect(DB::table('vendors')->pluck('slug')->sort()->values()->all())->toBe(['acme-supplies', 'acme-supplies-2']);
});
