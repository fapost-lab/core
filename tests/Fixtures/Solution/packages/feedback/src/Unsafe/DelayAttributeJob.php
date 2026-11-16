<?php

declare(strict_types=1);

namespace Acme\Feedback\Unsafe;

use Fapost\Foundation\Solution\Queue\SolutionJob;
use Illuminate\Queue\Attributes\Delay;

/**
 * A job of the fixture Solution that does something its dispatcher refuses; see CoreSolutionQueueTest.
 */
#[Delay(10)]
final class DelayAttributeJob extends SolutionJob
{
    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
    }
}
