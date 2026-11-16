<?php

declare(strict_types=1);

namespace Tests\Support;

use Fapost\Foundation\Solution\Queue\SolutionJob;

/**
 * A Solution job that lives in Core's tests, not in any Solution's package, and names the Solution it claims to belong
 * to: what a Solution trying to queue work in another's name looks like to the dispatcher. Also a plain job for tests
 * of the middleware, which does not look at where the class lives.
 */
final class ForeignSolutionJob extends SolutionJob
{
    public function __construct(public string $solutionId = 'feedback')
    {
    }

    public function solution(): string
    {
        return $this->solutionId;
    }

    protected function perform(): void
    {
    }
}
