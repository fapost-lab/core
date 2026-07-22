<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Orchestration;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowAccessPolicyInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Orchestration\FlowOrchestrator;
use App\Domains\Tenancy\Settings\TenantSettings;
use Closure;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;

/**
 * Covers the optimistic-lock retry loop in FlowOrchestrator: a
 * FlowConcurrencyException (the wrapped optimistic-lock conflict) is retried up
 * to MAX_OPTIMISTIC_RETRIES times, then rethrown.
 */
final class FlowOrchestratorRetryTest extends FeatureTestCase
{
    public function test_retries_on_concurrency_conflict_then_succeeds(): void
    {
        $calls    = 0;
        $sessions = $this->mock(FlowSessionRepositoryInterface::class, function (MockInterface $m) use (&$calls): void {
            $m->shouldReceive('findActiveForContact')->andReturnUsing(function () use (&$calls) {
                $calls++;
                if ($calls < 3) {
                    throw FlowConcurrencyException::forSession('session-x');
                }

                // Third attempt succeeds: no active session → fallback path (no-op here).
                return null;
            });
        });

        $orchestrator = $this->makeOrchestrator($sessions);

        $orchestrator->handle($this->contact(), $this->message(), 'assistant-1');

        $this->assertSame(3, $calls, 'Should retry twice then succeed on the third attempt.');
    }

    public function test_rethrows_after_exhausting_retries(): void
    {
        $calls    = 0;
        $sessions = $this->mock(FlowSessionRepositoryInterface::class, function (MockInterface $m) use (&$calls): void {
            $m->shouldReceive('findActiveForContact')->andReturnUsing(function () use (&$calls): void {
                $calls++;
                throw FlowConcurrencyException::forSession('session-x');
            });
        });

        $orchestrator = $this->makeOrchestrator($sessions);

        try {
            $orchestrator->handle($this->contact(), $this->message(), 'assistant-1');
            $this->fail('Expected FlowConcurrencyException was not thrown.');
        } catch (FlowConcurrencyException) {
            $this->assertSame(3, $calls, 'Should attempt exactly MAX_OPTIMISTIC_RETRIES times.');
        }
    }

    private function makeOrchestrator(FlowSessionRepositoryInterface $sessions): FlowOrchestrator
    {
        $guard = $this->mock(FlowExecutionGuardInterface::class, function (MockInterface $m): void {
            $m->shouldReceive('run')->andReturnUsing(
                static fn (string $t, string $c, string $a, Closure $callback) => $callback(),
            );
        });

        $currentAssistant = $this->mock(CurrentAssistantInterface::class, function (MockInterface $m): void {
            $m->shouldReceive('isResolved')->andReturnFalse();
        });

        return new FlowOrchestrator(
            guard: $guard,
            engine: Mockery::mock(FlowEngineInterface::class),
            sessions: $sessions,
            definitions: Mockery::mock(FlowDefinitionRepositoryInterface::class),
            currentAssistant: $currentAssistant,
            fallbackSender: Mockery::mock(FallbackMessageServiceInterface::class),
            persistentButtonRegistry: Mockery::mock(PersistentButtonRegistryInterface::class),
            translator: Mockery::mock(ContentTranslatorInterface::class),
            tenantSettings: $this->app->make(TenantSettings::class),
            accessPolicy: Mockery::mock(FlowAccessPolicyInterface::class),
        );
    }

    private function contact(): Contact
    {
        $contact = new Contact();
        $contact->forceFill([
            'id'        => '00000000-0000-0000-0000-0000000000c1',
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
        ]);

        return $contact;
    }

    private function message(): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'u-1',
            externalUserId: 'ext-1',
            externalChatId: 'chat-1',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );
    }
}
