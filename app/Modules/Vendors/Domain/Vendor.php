<?php

namespace App\Modules\Vendors\Domain;

use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A marketplace seller (ADR-0005). Its status changes only through
 * VendorLifecycle (dependency-rules.md L4) and is not mass assignable.
 * Membership and authorisation are later tasks.
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
#[UseFactory(VendorFactory::class)]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

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

    /**
     * Whether this Vendor may operate in the marketplace. Today that is derived
     * from its status alone, because no other marketplace condition exists yet
     * (Story #1): see VendorStatus::canOperate().
     */
    public function canOperate(): bool
    {
        return $this->status->canOperate();
    }
}
