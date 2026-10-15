<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution;

use ArrayObject;
use Fapost\Foundation\Contracts\CoreRegistrarInterface;
use Fapost\Foundation\Lifecycle\AbstractSolutionServiceProvider;
use Tests\Fixtures\Solution\Actions\FeedbackScoreAction;
use Tests\Fixtures\Solution\Actions\FeedbackSubmitAction;

/**
 * The provider of the fixture Solution in tests/Fixtures/Solution/packages/feedback. It registers both
 * actions its manifest declares and, when a test binds a log under {@see self::LOG}, writes its lifecycle there.
 */
final class FeedbackServiceProvider extends AbstractSolutionServiceProvider
{
    public const string LOG = 'fixture.feedback.log';

    protected function registerExtensions(CoreRegistrarInterface $registrar): void
    {
        $this->log('registerExtensions');

        $registrar->registerActionHandler(FeedbackSubmitAction::class);
        $registrar->registerActionHandler(FeedbackScoreAction::class);
    }

    protected function bootSolution(): void
    {
        $this->log('bootSolution');
    }

    private function log(string $step): void
    {
        if ($this->app->bound(self::LOG)) {
            $log = $this->app->make(self::LOG);

            if ($log instanceof ArrayObject) {
                $log->append($step);
            }
        }
    }
}
