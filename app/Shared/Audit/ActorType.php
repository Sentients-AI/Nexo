<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Who or what made an audited change (ADR-0023 Decision 1). The values are
 * the ones the audit_log_actor_type_check constraint accepts.
 */
enum ActorType: string
{
    /** A person, identified by an authenticated user id. */
    case User = 'user';

    /** A scheduled job or queue worker; the label names the job class. */
    case System = 'system';

    /** A console command; the label names the command, not who ran it. */
    case Console = 'console';
}
