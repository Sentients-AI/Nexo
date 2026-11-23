<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Modules\Vendors\Application\CreateVendor;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;

/**
 * Every worker creates a Vendor with the same slug at once, each under its
 * own name, through CreateVendor.
 *
 * Arguments: slug (string).
 */
final class CreateVendorWithSlug implements Scenario
{
    public function prepare(Application $app, int $worker, array $arguments): void
    {
        // Connect before the barrier, so every insert starts at the same moment.
        DB::connection()->getPdo();
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        $name = "Acme Worker {$worker}";

        try {
            $vendor = (new CreateVendor)->handle($name, (string) $arguments['slug']);
        } catch (VendorSlugAlreadyExists $exception) {
            $previous = $exception->getPrevious();

            return [
                'won' => false,
                'name' => $name,
                'id' => null,
                'index' => $previous instanceof UniqueConstraintViolationException ? $previous->index : null,
            ];
        }

        return ['won' => true, 'name' => $name, 'id' => $vendor->id, 'index' => null];
    }
}
