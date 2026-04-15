<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Assistant\Contracts\AssistantFlowConfigRepositoryInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use FAPost\Foundation\DTO\IncomingMessage;

final class FlowOrchestrator implements FlowOrchestratorInterface
{
    private const MAX_OPTIMISTIC_RETRIES = 3;

    public function __construct(
        private readonly FlowExecutionGuardInterface $guard,
        private readonly FlowEngineInterface $engine,
        private readonly FlowSessionRepositoryInterface $sessions,
        private readonly AssistantFlowConfigRepositoryInterface $assistantConfig,
        private readonly FlowDefinitionRepositoryInterface $definitions,
    ) {
    }

    public function handle(Contact $contact, IncomingMessage $message, string $assistantId): void
    {
        $this->guard->run(
            tenantId: (string) $contact->tenant_id,
            contactId: (string) $contact->getKey(),
            assistantId: $assistantId,
            callback: fn () => $this->executeWithOptimisticRetry($contact, $message, $assistantId),
        );
    }

    private function executeWithOptimisticRetry(Contact $contact, IncomingMessage $message, string $assistantId): void
    {
        $attempt = 0;

        while (true) {
            try {
                $session = $this->sessions->findActiveForContact($contact, $assistantId);

                if (null === $session) {
                    $defaultFlowId = $this->assistantConfig->findDefaultFlowId($assistantId);

                    if (null === $defaultFlowId) {
                        return;
                    }

                    $definition = $this->definitions->findLatestActiveByFlowId($defaultFlowId);

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
