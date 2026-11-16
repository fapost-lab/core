<?php

declare(strict_types=1);

namespace Acme\Feedback\Unsafe;

use Fapost\Foundation\Solution\Queue\SolutionJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * A job of the fixture Solution that does something its dispatcher refuses; see CoreSolutionQueueTest.
 */

final class UniqueJob extends SolutionJob implements ShouldBeUnique
{
    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
    }
}
