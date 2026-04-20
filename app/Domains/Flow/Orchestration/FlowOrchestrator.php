<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\Flow\DTO\ResolvedTrigger;

final class FlowOrchestrator implements FlowOrchestratorInterface
{
    private const int MAX_OPTIMISTIC_RETRIES = 3;

    public function __construct(
        private readonly FlowExecutionGuardInterface $guard,
        private readonly FlowEngineInterface $engine,
        private readonly FlowSessionRepositoryInterface $sessions,
        private readonly FlowDefinitionRepositoryInterface $definitions,
    ) {
    }

    public function handle(
        Contact $contact,
        IncomingMessage $message,
        string $assistantId,
        ?ResolvedTrigger $trigger = null
    ): void {
        $this->guard->run(
            tenantId: (string)$contact->tenant_id,
            contactId: (string)$contact->getKey(),
            assistantId: $assistantId,
            callback: fn () => $this->executeWithOptimisticRetry($contact, $message, $assistantId, $trigger),
        );
    }

    private function executeWithOptimisticRetry(
        Contact $contact,
        IncomingMessage $message,
        string $assistantId,
        ?ResolvedTrigger $trigger
    ): void {
        $attempt = 0;

        while (true) {
            try {
                $session = $this->sessions->findActiveForContact($contact, $assistantId);

                if (null === $session) {
                    if (null === $trigger) {
                        return;
                    }

                    $definition = $this->definitions->findLatestActiveByFlowId($trigger->flowId);

                    if (null === $definition) {
                        return;
                    }

                    $this->engine->start($definition, $contact, []);
                } else {
                    $this->engine->resume($session, $message);
                }

                return;
            } catch (FlowConcurrencyException $exception) {
                if (++$attempt >= self::MAX_OPTIMISTIC_RETRIES) {
                    throw $exception;
                }

                usleep(50_000 * $attempt);
            }
        }
    }
}
