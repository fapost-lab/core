<?php

declare(strict_types=1);

namespace Acme\Feedback\Unsafe;

use Fapost\Foundation\Solution\Queue\SolutionJob;
use Illuminate\Queue\Attributes\Queue;

/**
 * A job of the fixture Solution that does something its dispatcher refuses; see CoreSolutionQueueTest.
 */
#[Queue('default')]
abstract class QueueAttributeJob extends SolutionJob
{
    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
    }
}
