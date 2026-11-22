<?php

namespace App\Modules\Vendors\Domain;

use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marketplace seller (ADR-0005). Persistence only: lifecycle transitions,
 * membership and authorisation are later tasks. Status is not mass
 * assignable, so it can change only by an explicit assignment.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property VendorStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name'])]
#[DateFormat('Y-m-d H:i:s.u')]
class Vendor extends Model
{
    /**
     * A new Vendor is pending, matching the column default.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => VendorStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
        ];
    }
}
