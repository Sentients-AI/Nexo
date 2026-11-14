<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use Illuminate\Contracts\Foundation\Application;

/**
 * One operation that several worker processes run at the same moment.
 *
 * Each worker is a separate PHP process with its own application and its
 * own database connections. prepare() runs before the start barrier (open
 * connections, resolve services); run() runs after every worker is released.
 * Return only JSON-serialisable data.
 */
interface Scenario
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function prepare(Application $app, int $worker, array $arguments): void;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function run(Application $app, int $worker, array $arguments, WorkerContext $context): array;
}
