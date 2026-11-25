<?php

namespace Tests\Arch\Fixtures\L10;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A model with a relation onto the audit model. Analysed only, never executed. */
final class AuditedSubject extends Model
{
    /**
     * @return HasMany<AuditEntry, $this>
     */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class, 'subject_id');
    }
}
