<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Modules\Vendors\Application\ApproveVendor;
use App\Modules\Vendors\Application\ReactivateVendor;
use App\Modules\Vendors\Application\RegisterVendor;
use App\Modules\Vendors\Application\SuspendVendor;
use App\Modules\Vendors\Domain\InvalidVendorTransition;
use App\Modules\Vendors\Domain\Vendor;
use App\Modules\Vendors\Domain\VendorLifecycle;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use App\Shared\Audit\Actor;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;

/**
 * Every worker runs one audited Vendor orchestrator at once (#35), each in
 * its own process and connection, as the outermost transaction, so the
 * orchestrator's own retry policy applies. Any exception other than the
 * expected refusal fails the worker, and with it the test: exhausted
 * retries and lock-wait timeouts included.
 *
 * Arguments: operation ("register" | "approve" | "suspend" | "reactivate"),
 * vendor_id (int, lifecycle) or slug (string, register), label (the console
 * actor's label), control (optional bool, approve only): the negative
 * control, which writes the audit record in its own transaction before an
 * unaudited lifecycle call, instead of through ApproveVendor.
 */
final class RunAuditedVendorOperation implements Scenario
{
    /** Transaction attempts this worker made: its locking reads or Vendor inserts. */
    private int $attempts = 0;

    public function prepare(Application $app, int $worker, array $arguments): void
    {
        // Connect before the barrier, so every worker starts at the same moment.
        DB::connection()->getPdo();

        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_ends_with($query, 'for update') || str_starts_with($query, 'insert into `vendors`')) {
                $this->attempts++;
            }
        });
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        $actor = Actor::console((string) $arguments['label']);
        $operation = (string) $arguments['operation'];

        try {
            if ($operation === 'register') {
                $vendor = $app->make(RegisterVendor::class)->handle($actor, "Acme Worker {$worker}", (string) $arguments['slug']);
            } elseif (($arguments['control'] ?? false) === true) {
                $vendor = self::auditedOutsideTheTransaction($app, $actor, (int) $arguments['vendor_id']);
            } else {
                $orchestrator = match ($operation) {
                    'approve' => ApproveVendor::class,
                    'suspend' => SuspendVendor::class,
                    'reactivate' => ReactivateVendor::class,
                    default => throw new RuntimeException("Unknown operation '{$operation}'."),
                };

                $vendor = $app->make($orchestrator)->handle($actor, (int) $arguments['vendor_id']);
            }
        } catch (InvalidVendorTransition $exception) {
            return ['won' => false, 'refusal' => 'transition', 'actual_status' => $exception->actualStatus->value, 'id' => null, 'attempts' => $this->attempts];
        } catch (VendorSlugAlreadyExists) {
            return ['won' => false, 'refusal' => 'duplicate', 'actual_status' => null, 'id' => null, 'attempts' => $this->attempts];
        }

        return ['won' => true, 'refusal' => null, 'actual_status' => null, 'id' => $vendor->id, 'attempts' => $this->attempts];
    }

    /** The control: the record is committed on its own, so a refused change still leaves one. */
    private static function auditedOutsideTheTransaction(Application $app, Actor $actor, int $vendorId): Vendor
    {
        DB::transaction(fn () => $app->make(AuditRecorder::class)->record($actor, ApproveVendor::ACTION, ApproveVendor::SUBJECT_TYPE, $vendorId, ['status' => 'pending'], ['status' => 'approved']));

        $vendor = Vendor::query()->findOrFail($vendorId);

        // The negative control must bypass the audited orchestrator: that is the gap it shows.
        (new VendorLifecycle)->approve($vendor); // @phpstan-ignore nexo.vendorOrchestration

        return $vendor;
    }
}
