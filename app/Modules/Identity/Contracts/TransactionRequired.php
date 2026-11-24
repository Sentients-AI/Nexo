<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use LogicException;

/**
 * A row lock was requested with no open transaction on the connection. A
 * lock outside a transaction ends with its statement, so it would protect
 * nothing: this is a programming error in the caller.
 */
final class TransactionRequired extends LogicException
{
    public static function for(string $operation): self
    {
        return new self("{$operation} must run inside the caller's database transaction.");
    }
}
