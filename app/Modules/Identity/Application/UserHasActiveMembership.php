<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use DomainException;

final class UserHasActiveMembership extends DomainException {}
