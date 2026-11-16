<?php

declare(strict_types=1);

namespace Acme\Feedback\Unsafe;

use Fapost\Foundation\Solution\Queue\SolutionJob;

/**
 * A job of the fixture Solution that does something its dispatcher refuses; see CoreSolutionQueueTest.
 */

final class MiddlewarePropertyJob extends SolutionJob
{
    /** @var list<string> */
    public array $middleware = ['not-a-middleware'];

    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
    }
}
