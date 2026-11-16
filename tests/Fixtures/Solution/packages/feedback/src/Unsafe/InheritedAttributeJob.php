<?php

declare(strict_types=1);

namespace Acme\Feedback\Unsafe;

/**
 * A job of the fixture Solution that does something its dispatcher refuses; see CoreSolutionQueueTest.
 */

final class InheritedAttributeJob extends QueueAttributeJob
{
    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
    }
}
