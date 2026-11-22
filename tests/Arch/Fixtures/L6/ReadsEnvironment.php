<?php

namespace Tests\Arch\Fixtures\L6;

/** Violates L6: reads env() outside config/. */
final class ReadsEnvironment
{
    public function name(): mixed
    {
        return env('APP_NAME');
    }
}
