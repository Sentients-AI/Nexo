<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use RuntimeException;

final class TestDatabaseGuardViolation extends RuntimeException
{
    /**
     * @param  non-empty-list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            "TEST-DB-GUARD: refusing to run tests against this database configuration.\n"
            .implode("\n", array_map(static fn (string $v): string => 'TEST-DB-GUARD '.$v, $violations))
            ."\nSee docs/engineering/testing.md (test database guard)."
        );
    }
}
