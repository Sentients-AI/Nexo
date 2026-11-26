<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Domain;

enum MemberStatus: string
{
    case Active = 'active';
    case Deactivated = 'deactivated';
}
