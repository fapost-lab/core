<?php

declare(strict_types=1);

namespace Acme\Feedback\Unsafe;

use Fapost\Foundation\Solution\Queue\SolutionJob;

/**
 * A job of the fixture Solution that does something its dispatcher refuses; see CoreSolutionQueueTest.
 */

final class QueueMethodJob extends SolutionJob
{
    public function queue(): string
    {
        return 'default';
    }

    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
    }
}
