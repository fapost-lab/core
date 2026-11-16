<?php

declare(strict_types=1);

namespace Acme\Feedback\Unsafe;

use Fapost\Foundation\Solution\Queue\SolutionJob;
use Illuminate\Queue\Attributes\Timeout;

/**
 * A job of the fixture Solution that does something its dispatcher refuses; see CoreSolutionQueueTest.
 */
#[Timeout(5000)]
final class TimeoutAttributeJob extends SolutionJob
{
    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
    }
}
