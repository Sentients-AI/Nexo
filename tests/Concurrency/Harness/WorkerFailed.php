<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use RuntimeException;

/**
 * A worker exited with an error, stopped before the barrier, or reported no
 * result. Every other worker has been stopped.
 */
final class WorkerFailed extends RuntimeException
{
    public function __construct(public readonly int $worker, public readonly string $status, string $details)
    {
        parent::__construct("Concurrency worker {$worker} failed ({$status}):\n{$details}");
    }
}
