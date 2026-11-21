<?php

namespace Tests\Arch\Fixtures\M4\Modules\Payments\Application;

use Tests\Arch\Fixtures\M4\Modules\Ordering\Contracts\OrderSummaries;

/**
 * Violates M4: Payments calls Ordering. It goes through Contracts, so M1
 * holds, but the matrix has no Payments -> Ordering edge (footnote 2).
 */
final class CaptureAttempt
{
    public function __construct(private readonly OrderSummaries $orders) {}
}
