<?php

declare(strict_types=1);

use App\Modules\Vendors\Domain\VendorStatus;

// Operating eligibility (vendor-domain-design.md §6, §8; Story #1): only an
// approved Vendor may operate.

it('lets only an approved vendor operate', function (VendorStatus $status, bool $canOperate): void {
    expect($status->canOperate())->toBe($canOperate);
})->with([
    'pending' => [VendorStatus::Pending, false],
    'approved' => [VendorStatus::Approved, true],
    'suspended' => [VendorStatus::Suspended, false],
]);

it('answers for every lifecycle status', function (): void {
    expect(array_map(fn (VendorStatus $status): bool => $status->canOperate(), VendorStatus::cases()))
        ->toBe([false, true, false]);
});
