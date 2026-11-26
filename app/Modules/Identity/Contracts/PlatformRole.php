<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * A platform staff role (ADR-0011 Decision 3). A user holds at most one, in
 * `users.platform_role`; a user without one is not staff. The values match
 * the column's `users_platform_role_check` constraint exactly.
 *
 * GrantPlatformRole is the audited writer (ADR-0023).
 */
enum PlatformRole: string
{
    case Admin = 'admin';
    case Operations = 'operations';
    case Finance = 'finance';
    case Support = 'support';
}
