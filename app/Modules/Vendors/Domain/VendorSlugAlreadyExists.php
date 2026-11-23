<?php

namespace App\Modules\Vendors\Domain;

use DomainException;
use Throwable;

/**
 * A Vendor could not be created because another Vendor holds an equivalent
 * slug: the same one, or one that differs only in case or accents under the
 * slug column's collation (#29, owner decision D2). Nothing was written. The
 * database's unique-constraint violation is kept as the previous exception.
 */
final class VendorSlugAlreadyExists extends DomainException
{
    private function __construct(public readonly string $slug, Throwable $previous)
    {
        parent::__construct('A vendor with this slug already exists.', 0, $previous);
    }

    public static function for(string $slug, Throwable $previous): self
    {
        return new self($slug, $previous);
    }
}
