<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\PersistentInlineButton;
use App\Domains\Flow\Orchestration\FlowOrchestrator;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Tenancy\Settings\TenantSettings;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Flow\DTO\ResolvedTrigger;
use ReflectionClass;
use Tests\TestCase;

final class FlowOrchestratorTest extends TestCase
{
    public function test_it_starts_new_flow_when_no_active_session_exists(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $definition  = FlowDefinition::make();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->with($contact, 'assistant-1')->andReturn(null);
        $definitions->shouldReceive('findLatestActiveByFlowId')->once()->with('flow-1')->andReturn($definition);
        $engine->shouldReceive('start')->once()->with($definition, $contact, [])->andReturn(FlowSession::make());
        $engine->shouldNotReceive('resume');

        $this->orchestrator($guard, $engine, $sessions, $definitions)->handle(
            $contact,
            $message,
            'assistant-1',
            new ResolvedTrigger(triggerId: 'trigger-1', flowId: 'flow-1', type: 'message'),
        );
    }

    public function test_it_does_not_start_private_flow_for_unauthenticated_contact(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1', 'is_authenticated' => false]);
        $message     = $this->message();
        $definition  = FlowDefinition::make(['is_public' => false]);
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->with($contact, 'assistant-1')->andReturn(null);
        $definitions->shouldReceive('findLatestActiveByFlowId')->once()->with('flow-1')->andReturn($definition);
        $engine->shouldNotReceive('start');
        $engine->shouldNotReceive('resume');

        $this->orchestrator($guard, $engine, $sessions, $definitions)->handle(
            $contact,
            $message,
            'assistant-1',
            new ResolvedTrigger(triggerId: 'trigger-1', flowId: 'flow-1', type: 'message'),
        );
    }

    public function test_it_starts_private_flow_for_authenticated_contact(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1', 'is_authenticated' => true]);
        $message     = $this->message();
        $definition  = FlowDefinition::make(['is_public' => false]);
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->with($contact, 'assistant-1')->andReturn(null);
        $definitions->shouldReceive('findLatestActiveByFlowId')->once()->with('flow-1')->andReturn($definition);
        $engine->shouldReceive('start')->once()->with($definition, $contact, [])->andReturn(FlowSession::make());

        $this->orchestrator($guard, $engine, $sessions, $definitions)->handle(
            $contact,
            $message,
            'assistant-1',
            new ResolvedTrigger(triggerId: 'trigger-1', flowId: 'flow-1', type: 'message'),
        );
    }

    public function test_it_resumes_existing_session(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $session     = FlowSession::make();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->with($contact, 'assistant-1')->andReturn($session);
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldReceive('resume')->once()->with($session, $message)->andReturn($session);
        $engine->shouldNotReceive('start');

        $this->orchestrator($guard, $engine, $sessions, $definitions)->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_retries_after_optimistic_conflict_and_reloads_session(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $session     = FlowSession::make();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);
        $conflict    = FlowConcurrencyException::forSession('session-1');

        $sessions->shouldReceive('findActiveForContact')->twice()->with($contact, 'assistant-1')->andReturn($session);
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldReceive('resume')->once()->with($session, $message)->andThrow($conflict);
        $engine->shouldReceive('resume')->once()->with($session, $message)->andReturn($session);
        $engine->shouldNotReceive('start');

        $this->orchestrator($guard, $engine, $sessions, $definitions)->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_throws_after_all_optimistic_retries_are_exhausted(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $session     = FlowSession::make();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);
        $conflict    = FlowConcurrencyException::forSession('session-1');

        $sessions->shouldReceive('findActiveForContact')->times(3)->with($contact, 'assistant-1')->andReturn($session);
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldReceive('resume')->times(3)->with($session, $message)->andThrow($conflict);
        $engine->shouldNotReceive('start');

        $this->expectExceptionObject($conflict);

        $this->orchestrator($guard, $engine, $sessions, $definitions)->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_propagates_lock_timeout_exception(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $guard       = $this->mock(FlowExecutionGuardInterface::class);
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $guard->shouldReceive('run')->once()->andThrow(new SessionLockTimeoutException('session_lock:key'));
        $sessions->shouldNotReceive('findActiveForContact');
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldNotReceive('resume');
        $engine->shouldNotReceive('start');

        $this->expectException(SessionLockTimeoutException::class);

        $this->orchestrator($guard, $engine, $sessions, $definitions)->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_is_silent_when_no_trigger_and_no_assistant_context(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->with($contact, 'assistant-1')->andReturn(null);
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldNotReceive('resume');
        $engine->shouldNotReceive('start');

        // currentAssistant not resolved → no default flow, no fallback message
        $currentAssistant = $this->mock(CurrentAssistantInterface::class);
        $currentAssistant->shouldReceive('isResolved')->andReturn(false);
        $currentAssistant->shouldNotReceive('get');

        $fallbackSender = $this->mock(FallbackMessageServiceInterface::class);
        $fallbackSender->shouldNotReceive('send');

        $this->orchestrator($guard, $engine, $sessions, $definitions, $currentAssistant, $fallbackSender)
            ->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_starts_default_flow_when_no_trigger_but_assistant_has_default_flow(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $definition  = FlowDefinition::make();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->andReturn(null);
        $definitions->shouldReceive('findLatestActiveByFlowId')->once()->with('default-flow-id')->andReturn(
            $definition
        );
        $engine->shouldReceive('start')->once()->with($definition, $contact, [])->andReturn(FlowSession::make());
        $engine->shouldNotReceive('resume');

        $assistant = Assistant::factory()->make(
            ['default_flow_id' => 'default-flow-id', 'fallback_message' => null]
        );
        $currentAssistant = $this->mock(CurrentAssistantInterface::class);
        $currentAssistant->shouldReceive('isResolved')->andReturn(true);
        $currentAssistant->shouldReceive('get')->andReturn($assistant);

        $fallbackSender = $this->mock(FallbackMessageServiceInterface::class);
        $fallbackSender->shouldNotReceive('send');

        $this->orchestrator($guard, $engine, $sessions, $definitions, $currentAssistant, $fallbackSender)
            ->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_sends_fallback_when_no_trigger_and_no_default_flow(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->andReturn(null);
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldNotReceive('start');
        $engine->shouldNotReceive('resume');

        $assistant = Assistant::factory()->make(
            ['default_flow_id' => null, 'fallback_message' => 'Sorry, I did not understand you.']
        );
        $currentAssistant = $this->mock(CurrentAssistantInterface::class);
        $currentAssistant->shouldReceive('isResolved')->andReturn(true);
        $currentAssistant->shouldReceive('get')->andReturn($assistant);

        $fallbackSender = $this->mock(FallbackMessageServiceInterface::class);
        $fallbackSender->shouldReceive('send')
            ->once()
            ->with($contact, 'assistant-1', 'Sorry, I did not understand you.');

        $this->orchestrator($guard, $engine, $sessions, $definitions, $currentAssistant, $fallbackSender)
            ->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_is_silent_when_no_trigger_no_default_flow_and_empty_fallback_message(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->andReturn(null);
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldNotReceive('start');
        $engine->shouldNotReceive('resume');

        $assistant        = Assistant::factory()->make(['default_flow_id' => null, 'fallback_message' => null]);
        $currentAssistant = $this->mock(CurrentAssistantInterface::class);
        $currentAssistant->shouldReceive('isResolved')->andReturn(true);
        $currentAssistant->shouldReceive('get')->andReturn($assistant);

        $fallbackSender = $this->mock(FallbackMessageServiceInterface::class);
        $fallbackSender->shouldNotReceive('send');

        $this->orchestrator($guard, $engine, $sessions, $definitions, $currentAssistant, $fallbackSender)
            ->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_resumes_from_persistent_button_when_no_active_session(): void
    {
        $sessionId = '01951a72-3c9a-7001-8000-000000000001';
        $buttonId  = '01951a72-3c9a-7001-8000-000000000002';
        $cbData    = CallbackDataCodec::encode($sessionId, $buttonId);

        $contact    = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message    = $this->callbackMessage($cbData);
        $definition = FlowDefinition::make(['id' => 'def-1', 'version' => 1]);
        $guard      = $this->guardThatRunsCallback();
        $sessions   = $this->mock(FlowSessionRepositoryInterface::class);
        $engine     = $this->mock(FlowEngineInterface::class);
        $registry   = $this->mock(PersistentButtonRegistryInterface::class);

        $registration                     = new PersistentInlineButton();
        $registration->flow_definition_id = 'def-1';
        $registration->node_id            = 'node-abc';

        $sessions->shouldReceive('findActiveForContact')->once()->andReturn(null);
        $registry->shouldReceive('find')
            ->once()
            ->with((string)$contact->tenant_id, (string)$contact->getKey(), $sessionId, $buttonId)
            ->andReturn($registration);

        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $definitions->shouldReceive('findById')->once()->with('def-1')->andReturn($definition);

        $engine->shouldReceive('resumeFromNode')->once()->with($definition, $contact, 'node-abc', $buttonId);
        $engine->shouldNotReceive('resume');
        $engine->shouldNotReceive('start');
        $sessions->shouldNotReceive('cancel');

        $this->orchestrator($guard, $engine, $sessions, $definitions, registry: $registry)
            ->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_cancels_active_session_and_resumes_from_persistent_button(): void
    {
        $sessionId = '01951a72-3c9a-7001-8000-000000000001';
        $buttonId  = '01951a72-3c9a-7001-8000-000000000002';
        $cbData    = CallbackDataCodec::encode($sessionId, $buttonId);

        $contact       = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message       = $this->callbackMessage($cbData);
        $definition    = FlowDefinition::make(['id' => 'def-1', 'version' => 1]);
        $activeSession = FlowSession::make(['id' => 'other-session-id']);
        $guard         = $this->guardThatRunsCallback();
        $sessions      = $this->mock(FlowSessionRepositoryInterface::class);
        $engine        = $this->mock(FlowEngineInterface::class);
        $registry      = $this->mock(PersistentButtonRegistryInterface::class);
        $definitions   = $this->mock(FlowDefinitionRepositoryInterface::class);

        $registration                     = new PersistentInlineButton();
        $registration->flow_definition_id = 'def-1';
        $registration->node_id            = 'node-abc';

        $sessions->shouldReceive('findActiveForContact')->once()->andReturn($activeSession);
        $registry->shouldReceive('find')->once()->andReturn($registration);
        $definitions->shouldReceive('findById')->once()->andReturn($definition);
        $sessions->shouldReceive('cancel')->once()->with($activeSession);
        $engine->shouldReceive('resumeFromNode')->once()->with($definition, $contact, 'node-abc', $buttonId);
        $engine->shouldNotReceive('resume');

        $this->orchestrator($guard, $engine, $sessions, $definitions, registry: $registry)
            ->handle($contact, $message, 'assistant-1', null);
    }

    public function test_it_does_not_check_registry_when_active_session_owns_callback(): void
    {
        $sessionId = '01951a72-3c9a-7001-8000-000000000001';
        $buttonId  = '01951a72-3c9a-7001-8000-000000000002';
        $cbData    = CallbackDataCodec::encode($sessionId, $buttonId);

        $contact       = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message       = $this->callbackMessage($cbData);
        $activeSession = tap(new FlowSession(), fn ($s) => $s->setAttribute('id', $sessionId));
        $guard         = $this->guardThatRunsCallback();
        $sessions      = $this->mock(FlowSessionRepositoryInterface::class);
        $engine        = $this->mock(FlowEngineInterface::class);
        $registry      = $this->mock(PersistentButtonRegistryInterface::class);
        $definitions   = $this->mock(FlowDefinitionRepositoryInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->andReturn($activeSession);
        $registry->shouldNotReceive('find'); // session matches → skip registry
        $engine->shouldReceive('resume')->once()->with($activeSession, $message)->andReturn($activeSession);
        $engine->shouldNotReceive('resumeFromNode');

        $this->orchestrator($guard, $engine, $sessions, $definitions, registry: $registry)
            ->handle($contact, $message, 'assistant-1', null);
    }

    private function message(): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'up-1',
            externalUserId: 'user-1',
            externalChatId: 'chat-1',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
            payload: [],
        );
    }

    private function callbackMessage(string $callbackData): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'up-1',
            externalUserId: 'user-1',
            externalChatId: 'chat-1',
            text: $callbackData,
            type: IncomingMessageType::CallbackQuery,
            platform: 'telegram',
            payload: [],
        );
    }

    private function guardThatRunsCallback(): FlowExecutionGuardInterface
    {
        $guard = $this->mock(FlowExecutionGuardInterface::class);

        $guard->shouldReceive('run')->once()->andReturnUsing(
            static fn (string $tenantId, string $contactId, string $assistantId, callable $callback): mixed => $callback(
            ),
        );

        return $guard;
    }

    private function orchestrator(
        FlowExecutionGuardInterface $guard,
        FlowEngineInterface $engine,
        FlowSessionRepositoryInterface $sessions,
        FlowDefinitionRepositoryInterface $definitions,
        ?CurrentAssistantInterface $currentAssistant = null,
        ?FallbackMessageServiceInterface $fallbackSender = null,
        ?PersistentButtonRegistryInterface $registry = null,
    ): FlowOrchestrator {
        $currentAssistant ??= tap(
            $this->mock(CurrentAssistantInterface::class),
            fn ($m) => $m->shouldReceive('isResolved')->andReturn(false)->byDefault()
        );
        $fallbackSender ??= tap(
            $this->mock(FallbackMessageServiceInterface::class),
            fn ($m) => $m->shouldNotReceive('send')->byDefault()
        );
        $registry ??= tap(
            $this->mock(PersistentButtonRegistryInterface::class),
            fn ($m) => $m->shouldNotReceive('find')->byDefault()
        );

        $translator = tap(
            $this->mock(ContentTranslatorInterface::class),
            fn ($m) => $m->shouldReceive('resolveField')->byDefault()->andReturnUsing(
                static fn (mixed $field): string => is_string($field) ? $field : '',
            ),
        );

        $settings                    = (new ReflectionClass(TenantSettings::class))->newInstanceWithoutConstructor();
        $settings->fallback_language = 'en';

        return new FlowOrchestrator(
            $guard,
            $engine,
            $sessions,
            $definitions,
            $currentAssistant,
            $fallbackSender,
            $registry,
            $translator,
            $settings,
            new \App\Domains\Flow\Services\FlowAccessPolicy(),
        );
    }
}
