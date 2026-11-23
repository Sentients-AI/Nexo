<?php

namespace App\Modules\Vendors\Infrastructure\Console;

use App\Modules\Vendors\Application\CreateVendor;
use App\Modules\Vendors\Domain\InvalidVendorDetails;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * The operator's console entry to CreateVendor (Story #1 Task 4, #29 D6). It
 * runs only from the console, through the Vendors provider. It is not an
 * authenticated or audited management interface: there is no operator
 * identity yet (ADR-0011 is Proposed) and no audit log (D3). In production
 * it asks for confirmation, and it refuses without --force when it cannot ask.
 *
 * The input is validated by CreateVendor itself, so this command adds no
 * second copy of the rules: it reports them.
 */
final class CreateVendorCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'vendors:create
        {slug : The public slug: 3 to 80 characters of lowercase a-z, 0-9 and single hyphens}
        {name : The display name, up to 255 characters}
        {--force : Create the vendor in production without asking}';

    protected $description = 'Create a pending Vendor (operator console only; not authenticated or audited)';

    public function handle(CreateVendor $createVendor): int
    {
        if (! $this->confirmToProceed('This creates a Vendor without authentication or an audit record.')) {
            return self::FAILURE;
        }

        try {
            $vendor = $createVendor->handle($this->argument('name'), $this->argument('slug'));
        } catch (InvalidVendorDetails $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        } catch (VendorSlugAlreadyExists $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Created vendor %d (%s) as %s. It cannot operate until it is approved.',
            $vendor->id,
            $vendor->slug,
            $vendor->status->value,
        ));

        return self::SUCCESS;
    }
}
