<?php

declare(strict_types=1);

namespace Tests\Support\Redis;

/**
 * The phpredis methods NonExecutingProbe::violationsOn() uses, so tests can
 * drive it without the extension. Production code passes a real \Redis.
 */
interface ProbeClient
{
    public function setOption(int $option, mixed $value): bool;

    public function getOption(int $option): mixed;

    public function rawCommand(string $command, mixed ...$arguments): mixed;

    public function clearLastError(): bool;

    public function getLastError(): ?string;

    public function close(): bool;
}
