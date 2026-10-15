<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution\Actions;

use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Flow\Call\CallContext;
use Tests\Fixtures\Solution\FeedbackCalls;
use Tests\Fixtures\Solution\FeedbackProbe;

/**
 * The `feedback.submit` action of the fixture Solution: echoes its parameters with the tenant it ran for.
 */
final class FeedbackSubmitAction implements ActionHandlerInterface
{
    public function __construct(
        private readonly FeedbackCalls $calls,
        private readonly FeedbackProbe $probe,
    ) {
        ++$this->calls->constructions;
    }

    public function id(): string
    {
        return 'feedback.submit';
    }

    public function version(): int
    {
        return 1;
    }

    public function handle(array $parameters, CallContext $context): mixed
    {
        $this->calls->calls[] = ['action' => $this->id(), 'tenant_id' => $context->tenantId, 'parameters' => $parameters];

        return ['echo' => $parameters, 'tenant_id' => $context->tenantId, 'scope' => $this->probe->scopeToken];
    }
}
