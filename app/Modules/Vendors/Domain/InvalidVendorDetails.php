<?php

namespace App\Modules\Vendors\Domain;

use DomainException;

/**
 * A Vendor's name or slug breaks its rule (#29, owner decision D1), so no
 * Vendor was created. The message states the rule, never the rejected value.
 */
final class InvalidVendorDetails extends DomainException
{
    private function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function name(int $maxLength): self
    {
        return new self('name', sprintf(
            'The vendor name must be valid UTF-8 text of 1 to %d characters, not counting surrounding whitespace.',
            $maxLength,
        ));
    }

    public static function slug(): self
    {
        return new self(
            'slug',
            'The vendor slug must be 3 to 80 characters of lowercase a-z, 0-9 and single hyphens, and must not start or end with a hyphen.',
        );
    }
}
