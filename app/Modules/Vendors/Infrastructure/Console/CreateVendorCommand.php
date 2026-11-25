<?php

namespace App\Modules\Vendors\Infrastructure\Console;

use App\Modules\Vendors\Application\RegisterVendor;
use App\Modules\Vendors\Domain\InvalidVendorDetails;
use App\Modules\Vendors\Domain\VendorSlugAlreadyExists;
use App\Shared\Audit\Actor;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * The operator's console entry to RegisterVendor (Story #1 Task 4, #29 D6;
 * #35). It runs only from the console, through the Vendors provider.
 *
 * It is audited but not authenticated. The audit record's actor is a console
 * actor labelled with this command's name: provenance, not attribution
 * (ADR-0023 Decision 2). Nothing in its input names who ran it, and it offers
 * no option to claim an identity: no authenticated operator exists yet
 * (ADR-0011 is accepted but authentication is not built). In production it
 * asks for confirmation, and it refuses without --force when it cannot ask.
 *
 * The input is validated by CreateVendor, through RegisterVendor, so this
 * command adds no second copy of the rules: it reports them.
 */
final class CreateVendorCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'vendors:create
        {slug : The public slug: 3 to 80 characters of lowercase a-z, 0-9 and single hyphens}
        {name : The display name, up to 255 characters}
        {--force : Create the vendor in production without asking}';

    protected $description = 'Create a pending Vendor (operator console only; audited as console provenance, not authenticated)';

    public function handle(RegisterVendor $registerVendor): int
    {
        if (! $this->confirmToProceed('This creates a Vendor without authentication; the audit log records the command, not who ran it.')) {
            return self::FAILURE;
        }

        try {
            $vendor = $registerVendor->handle(
                Actor::console((string) $this->getName()),
                $this->argument('name'),
                $this->argument('slug'),
            );
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
