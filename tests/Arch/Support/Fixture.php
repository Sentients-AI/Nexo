<?php

namespace Tests\Arch\Support;

use Closure;
use PHPUnit\Framework\AssertionFailedError;

final class Fixture
{
    /**
     * Runs a rule against a deliberate-violation fixture and returns the
     * failure it raised. A rule that accepts its fixture fails the test, and so
     * does a fixture that does not load: a rule over a namespace with no
     * classes passes.
     */
    public static function rejects(Closure $rule): AssertionFailedError
    {
        try {
            $rule();
        } catch (AssertionFailedError $failure) {
            return $failure;
        }

        throw new AssertionFailedError('The rule accepted its deliberate-violation fixture.');
    }
}
