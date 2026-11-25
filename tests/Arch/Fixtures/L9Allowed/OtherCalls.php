<?php

namespace Tests\Arch\Fixtures\L9Allowed;

use App\Modules\Vendors\Application\ApproveVendor;
use App\Modules\Vendors\Application\CreateVendor;
use App\Modules\Vendors\Application\RegisterVendor;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorLifecycle;
use App\Shared\Audit\Actor;

/**
 * Not reported by L9. The first method calls the orchestrators and other
 * code; the second holds the rule's known blind spots, which it cannot see
 * statically (documented in VendorOrchestrationRule). Analysed only, never
 * executed. The real orchestrators are proven allowed on their own files.
 */
final class OtherCalls
{
    public function allowed(RegisterVendor $register, ApproveVendor $approve, Vendor $vendor, Workflow $workflow): void
    {
        $register->handle(Actor::console('vendors:create'), 'Acme', 'acme');
        $approve->handle(Actor::console('vendors:approve'), 1);

        new CreateVendor;
        new VendorLifecycle;
        $vendor->canOperate();

        $workflow->approve($vendor);
        $workflow->handle('Acme', 'acme');
        $callable = [$workflow, 'suspend'];
        $callable($vendor);
    }

    public function blindSpots(mixed $service, VendorLifecycle $lifecycle, Vendor $vendor, string $method): void
    {
        $service->approve($vendor);
        app('vendors.lifecycle')->suspend($vendor);
        $lifecycle->{$method}($vendor);
        $approve = $lifecycle->approve(...);
        $approve($vendor);
        call_user_func([$lifecycle, $method], $vendor);
        (new \ReflectionMethod(VendorLifecycle::class, 'reactivate'))->invoke($lifecycle, $vendor);
    }
}

/** Methods with the protected operations' names, on another class. */
final class Workflow
{
    public function approve(Vendor $vendor): void {}

    public function suspend(Vendor $vendor): void {}

    public function handle(string $name, string $slug): void {}
}
