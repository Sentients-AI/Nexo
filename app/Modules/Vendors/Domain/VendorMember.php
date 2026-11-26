<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Domain;

use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_id
 * @property int $user_id
 * @property MemberRole $role
 * @property MemberStatus $status
 * @property Carbon|null $deactivated_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
final class VendorMember extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'status' => MemberStatus::class,
            'deactivated_at' => 'datetime',
        ];
    }
}
