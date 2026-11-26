<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Domain;

enum MemberRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Fulfilment = 'fulfilment';
    case Finance = 'finance';
    case Support = 'support';
}
