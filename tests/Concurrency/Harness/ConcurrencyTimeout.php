<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The run exceeded its overall timeout. Every worker was killed first.
 */
final class ConcurrencyTimeout extends RuntimeException
{
    /**
     * @param  list<Process>  $processes  The killed workers, for inspection.
     */
    public function __construct(public readonly float $timeoutSeconds, public readonly array $processes)
    {
        parent::__construct(sprintf(
            'Concurrency run exceeded %.1fs; %d worker(s) killed.',
            $timeoutSeconds,
            count($processes),
        ));
    }
}
