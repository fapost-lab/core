<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Assistant\Contracts\AssistantFlowConfigRepositoryInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\FlowOrchestrator;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
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
        $assistant   = $this->mock(AssistantFlowConfigRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->with($contact, 'assistant-1')->andReturn(null);
        $assistant->shouldReceive('findDefaultFlowId')->once()->with('assistant-1')->andReturn('flow-1');
        $definitions->shouldReceive('findLatestActiveByFlowId')->once()->with('flow-1')->andReturn($definition);
        $engine->shouldReceive('start')->once()->with($definition, $contact, [])->andReturn(FlowSession::make());
        $engine->shouldNotReceive('resume');

        $orchestrator = new FlowOrchestrator($guard, $engine, $sessions, $assistant, $definitions);
        $orchestrator->handle($contact, $message, 'assistant-1');
    }

    public function test_it_resumes_existing_session(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $session     = FlowSession::make();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $assistant   = $this->mock(AssistantFlowConfigRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $sessions->shouldReceive('findActiveForContact')->once()->with($contact, 'assistant-1')->andReturn($session);
        $assistant->shouldNotReceive('findDefaultFlowId');
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldReceive('resume')->once()->with($session, $message)->andReturn($session);
        $engine->shouldNotReceive('start');

        $orchestrator = new FlowOrchestrator($guard, $engine, $sessions, $assistant, $definitions);
        $orchestrator->handle($contact, $message, 'assistant-1');
    }

    public function test_it_retries_after_optimistic_conflict_and_reloads_session(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $session     = FlowSession::make();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $assistant   = $this->mock(AssistantFlowConfigRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);
        $conflict    = FlowConcurrencyException::forSession('session-1');

        $sessions->shouldReceive('findActiveForContact')->twice()->with($contact, 'assistant-1')->andReturn($session);
        $assistant->shouldNotReceive('findDefaultFlowId');
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldReceive('resume')->once()->with($session, $message)->andThrow($conflict);
        $engine->shouldReceive('resume')->once()->with($session, $message)->andReturn($session);
        $engine->shouldNotReceive('start');

        $orchestrator = new FlowOrchestrator($guard, $engine, $sessions, $assistant, $definitions);
        $orchestrator->handle($contact, $message, 'assistant-1');
    }

    public function test_it_throws_after_all_optimistic_retries_are_exhausted(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $session     = FlowSession::make();
        $guard       = $this->guardThatRunsCallback();
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $assistant   = $this->mock(AssistantFlowConfigRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);
        $conflict    = FlowConcurrencyException::forSession('session-1');

        $sessions->shouldReceive('findActiveForContact')->times(3)->with($contact, 'assistant-1')->andReturn($session);
        $assistant->shouldNotReceive('findDefaultFlowId');
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldReceive('resume')->times(3)->with($session, $message)->andThrow($conflict);
        $engine->shouldNotReceive('start');

        $orchestrator = new FlowOrchestrator($guard, $engine, $sessions, $assistant, $definitions);

        $this->expectExceptionObject($conflict);

        $orchestrator->handle($contact, $message, 'assistant-1');
    }

    public function test_it_propagates_lock_timeout_exception(): void
    {
        $contact     = Contact::factory()->make(['tenant_id' => 'tenant-1']);
        $message     = $this->message();
        $guard       = $this->mock(FlowExecutionGuardInterface::class);
        $sessions    = $this->mock(FlowSessionRepositoryInterface::class);
        $assistant   = $this->mock(AssistantFlowConfigRepositoryInterface::class);
        $definitions = $this->mock(FlowDefinitionRepositoryInterface::class);
        $engine      = $this->mock(FlowEngineInterface::class);

        $guard->shouldReceive('run')->once()->andThrow(new SessionLockTimeoutException('session_lock:key'));
        $sessions->shouldNotReceive('findActiveForContact');
        $assistant->shouldNotReceive('findDefaultFlowId');
        $definitions->shouldNotReceive('findLatestActiveByFlowId');
        $engine->shouldNotReceive('resume');
        $engine->shouldNotReceive('start');

        $orchestrator = new FlowOrchestrator($guard, $engine, $sessions, $assistant, $definitions);

        $this->expectException(SessionLockTimeoutException::class);

        $orchestrator->handle($contact, $message, 'assistant-1');
    }

    private function guardThatRunsCallback(): FlowExecutionGuardInterface
    {
        $guard = $this->mock(FlowExecutionGuardInterface::class);

        $guard->shouldReceive('run')->once()->andReturnUsing(
            static fn (string $tenantId, string $contactId, string $assistantId, callable $callback): mixed => $callback(),
        );

        return $guard;
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
}
