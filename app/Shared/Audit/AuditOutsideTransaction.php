<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use LogicException;

/**
 * An audit record was written with no open transaction on the connection that
 * writes it. Committed alone, the record could outlive a change that rolls
 * back, or exist for a change that never happened (ADR-0023 Decision 4), so
 * this is a programming error in the caller. Nothing was written.
 */
final class AuditOutsideTransaction extends LogicException
{
    public static function onConnection(string $connection): self
    {
        return new self("AuditRecorder::record() must run inside the caller's database transaction on the [{$connection}] connection.");
    }
}
