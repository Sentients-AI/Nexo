<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Modules\Vendors\Domain\InvalidVendorTransition;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorLifecycle;
use App\Modules\Vendors\Domain\VendorStatus;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;

/**
 * Every worker loads the same vendor before the barrier, so all hold the same
 * model, then runs one lifecycle operation on it at once.
 *
 * Arguments: vendor_id (int), operation ("approve" | "suspend"), naive
 * (optional bool): the negative control, which checks the loaded model and
 * then saves, instead of the guarded update.
 */
final class TransitionVendor implements Scenario
{
    private ?Vendor $vendor = null;

    public function prepare(Application $app, int $worker, array $arguments): void
    {
        $this->vendor = Vendor::findOrFail((int) $arguments['vendor_id']);
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        $vendor = $this->vendor ?? throw new RuntimeException('prepare() did not load the vendor.');
        $operation = (string) $arguments['operation'];

        try {
            if (($arguments['naive'] ?? false) === true) {
                self::naively($vendor, $operation);
            } else {
                (new VendorLifecycle)->{$operation}($vendor);
            }
        } catch (InvalidVendorTransition $exception) {
            return ['won' => false, 'actual_status' => $exception->actualStatus->value, 'written_at' => null];
        }

        return ['won' => true, 'actual_status' => null, 'written_at' => $vendor->updated_at?->format('Y-m-d H:i:s.u')];
    }

    /** Read-then-write: the race the lifecycle's conditional update prevents. */
    private static function naively(Vendor $vendor, string $operation): void
    {
        [$from, $to] = match ($operation) {
            'approve' => [VendorStatus::Pending, VendorStatus::Approved],
            'suspend' => [VendorStatus::Approved, VendorStatus::Suspended],
            default => throw new RuntimeException("Unknown operation '{$operation}'."),
        };

        if ($vendor->status !== $from) {
            throw InvalidVendorTransition::for((int) $vendor->getKey(), $operation, $from, $vendor->status);
        }

        // The negative control must write the status unguarded: that is the race it shows.
        $vendor->status = $to; // @phpstan-ignore nexo.vendorStatusWrite
        $vendor->save();
    }
}
