<?php

declare(strict_types=1);

namespace Acme\Feedback\Unsafe;

use Fapost\Foundation\Solution\Queue\SolutionJob;

/**
 * An intermediate parent with a private property: invisible to a scan of the subclass's own properties.
 */
abstract class HoldingJob extends SolutionJob
{
    public function __construct(private mixed $held = null)
    {
    }

    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
    }
}
