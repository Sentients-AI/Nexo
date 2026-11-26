<?php

declare(strict_types=1);

namespace Tests\Concurrency\Scenarios;

use App\Modules\Identity\Application\GrantPlatformRole;
use App\Modules\Identity\Application\UserHasActiveMembership;
use App\Modules\Identity\Contracts\PlatformRole;
use App\Modules\Vendors\Application\AssignFirstOwner;
use App\Modules\Vendors\Application\CannotDeactivateOwner;
use App\Modules\Vendors\Application\DeactivateMember;
use App\Modules\Vendors\Application\InvalidOwnershipTarget;
use App\Modules\Vendors\Application\MemberNotActive;
use App\Modules\Vendors\Application\OwnershipChanged;
use App\Modules\Vendors\Application\TransferOwnership;
use App\Modules\Vendors\Application\UserAlreadyMember;
use App\Modules\Vendors\Application\UserIsPlatformStaff;
use App\Modules\Vendors\Application\VendorAlreadyOwned;
use App\Shared\Audit\Actor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concurrency\Harness\Scenario;
use Tests\Concurrency\Harness\WorkerContext;

final class RunMembershipOperation implements Scenario
{
    public function prepare(Application $app, int $worker, array $arguments): void
    {
        DB::connection()->getPdo();
    }

    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array
    {
        [$operation, $vendorId, $first, $second] = $arguments['calls'][$worker];
        $actor = Actor::console('test:membership-race');

        try {
            switch ($operation) {
                case 'assign':
                    $app->make(AssignFirstOwner::class)->handle($actor, $vendorId, $first);
                    break;
                case 'transfer':
                    $app->make(TransferOwnership::class)->handle($actor, $vendorId, $first, $second);
                    break;
                case 'deactivate':
                    $app->make(DeactivateMember::class)->handle($actor, $vendorId, $first);
                    break;
                case 'grant':
                    $app->make(GrantPlatformRole::class)->handle($actor, $first, PlatformRole::Admin);
                    break;
                default:
                    throw new LogicException("Unknown membership operation: {$operation}.");
            }
        } catch (VendorAlreadyOwned|UserAlreadyMember|UserIsPlatformStaff|CannotDeactivateOwner|InvalidOwnershipTarget|OwnershipChanged|MemberNotActive|UserHasActiveMembership $exception) {
            return ['outcome' => $exception::class];
        }

        return ['outcome' => 'success'];
    }
}
