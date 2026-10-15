<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution\Actions;

use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Flow\Call\CallContext;

/**
 * The `feedback.score` action of the fixture Solution.
 */
final class FeedbackScoreAction implements ActionHandlerInterface
{
    public function id(): string
    {
        return 'feedback.score';
    }

    public function version(): int
    {
        return 1;
    }

    public function handle(array $parameters, CallContext $context): mixed
    {
        return ['score' => 5];
    }
}
