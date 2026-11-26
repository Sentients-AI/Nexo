<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Infrastructure\Console;

use App\Modules\Vendors\Application\ReassignOwner;
use App\Shared\Audit\Actor;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ReassignOwnerCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'vendors:reassign-owner {vendor} {owner} {replacement} {--force : Proceed in production without confirmation}';

    protected $description = 'Change vendor ownership (operator console, audited as console provenance)';

    public function handle(ReassignOwner $operation): int
    {
        $ids = [];
        foreach (['vendor', 'owner', 'replacement'] as $name) {
            $argument = $this->argument($name);
            $id = filter_var($argument, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $this->components->error("The {$name} must be a positive integer ID.");

                return self::INVALID;
            }
            $ids[] = $id;
        }

        if (! $this->confirmToProceed('No authentication. Audit identifies the command, not the operator.')) {
            return self::FAILURE;
        }

        try {
            $operation->handle(Actor::console('vendors:reassign-owner'), $ids[0], $ids[1], $ids[2]);
        } catch (DomainException|ModelNotFoundException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Vendor ownership updated.');

        return self::SUCCESS;
    }
}
