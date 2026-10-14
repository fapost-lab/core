<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution;

use Fapost\Foundation\Contracts\CoreRegistrarInterface;
use Fapost\Foundation\Lifecycle\AbstractSolutionServiceProvider;

/**
 * The provider of the fixture Solution in tests/Fixtures/Solution/packages/feedback.
 */
final class FeedbackServiceProvider extends AbstractSolutionServiceProvider
{
    protected function registerExtensions(CoreRegistrarInterface $registrar): void
    {
    }
}
