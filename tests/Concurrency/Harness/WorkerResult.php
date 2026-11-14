<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

/**
 * What one worker reported. Times are microtime(true) in the worker.
 */
final readonly class WorkerResult
{
    /**
     * @param  array<string, mixed>  $data  The scenario's return value.
     */
    public function __construct(
        public int $worker,
        public float $readyAt,
        public float $releasedAt,
        public float $finishedAt,
        public array $data,
    ) {}

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
