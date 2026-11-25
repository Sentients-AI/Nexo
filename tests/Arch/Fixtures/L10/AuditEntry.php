<?php

namespace Tests\Arch\Fixtures\L10;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An audit model, as L10 recognises one: its table is audit_log. The
 * application has none. Analysed by AuditLogMutationRuleTest only, never
 * executed.
 */
final class AuditEntry extends Model
{
    use SoftDeletes;

    protected $table = 'audit_log';
}
