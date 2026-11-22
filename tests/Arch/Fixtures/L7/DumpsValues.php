<?php

namespace Tests\Arch\Fixtures\L7;

/** Violates L7: leaves a debug helper in committed code. */
final class DumpsValues
{
    public function inspect(mixed $value): void
    {
        dump($value);
    }
}
