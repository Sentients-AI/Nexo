<?php

namespace Tests\Arch\Fixtures\L9;

use App\Modules\Vendors\Application\CreateVendor;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorLifecycle;

/**
 * Violates L9: audited Vendor operations reached without their
 * orchestrators. Analysed by VendorOrchestrationRuleTest only, never
 * executed. Each reported line is marked.
 */
final class Bypasses
{
    public function __construct(
        private readonly CreateVendor $createVendor,
        private readonly VendorLifecycle $lifecycle,
    ) {}

    public function injected(Vendor $vendor): void
    {
        $this->createVendor->handle('Acme', 'acme'); // violation
        $this->lifecycle->approve($vendor); // violation
        $this->lifecycle->suspend($vendor); // violation
        $this->lifecycle->reactivate($vendor); // violation
    }

    public function constructed(Vendor $vendor, ?VendorLifecycle $maybe, VendorLifecycle $parameter): void
    {
        (new CreateVendor)->handle('Acme', 'acme'); // violation
        (new VendorLifecycle)->approve($vendor); // violation
        app(CreateVendor::class)->handle('Acme', 'acme'); // violation
        app()->make(VendorLifecycle::class)->suspend($vendor); // violation
        $maybe?->reactivate($vendor); // violation
        $parameter->approve($vendor); // violation

        $lifecycle = new VendorLifecycle;
        $lifecycle->suspend($vendor); // violation
    }

    public function callables(Vendor $vendor): void
    {
        $reactivate = [$this->lifecycle, 'reactivate']; // violation
        $reactivate($vendor);

        array_map([$this->createVendor, 'handle'], ['Acme'], ['acme']); // violation
    }
}
