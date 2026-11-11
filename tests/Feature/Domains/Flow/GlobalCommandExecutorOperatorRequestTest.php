<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Commands\GlobalCommandExecutor;
use App\Domains\Flow\Commands\ResolvedCommand;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;

/**
 * A command that ends a flow session takes the session's pending operator request with it
 * ({@see GlobalCommandExecutor}): the thread goes back to the bot instead of waiting for an operator forever.
 */
final class GlobalCommandExecutorOperatorRequestTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private MockInterface&OperatorRequestsInterface $operatorRequests;

    private MockInterface&FlowSessionRepositoryInterface $sessions;

    private MockInterface&FlowDefinitionRepositoryInterface $definitions;

    private MockInterface&FlowEngineInterface $engine;

    private Assistant $assistant;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatorRequests = Mockery::mock(OperatorRequestsInterface::class);
        $this->sessions         = Mockery::mock(FlowSessionRepositoryInterface::class);
        $this->definitions      = Mockery::mock(FlowDefinitionRepositoryInterface::class);
        $this->engine           = Mockery::mock(FlowEngineInterface::class);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
    }

    public function test_reset_with_an_active_session_cancels_that_sessions_operator_request(): void
    {
        $session = $this->activeSession();
        $this->sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);
        $this->operatorRequests->shouldReceive('cancelForSession')
            ->once()
            ->with(self::TENANT_ID, (string) $this->assistant->getKey(), (string) $this->contact->getKey(), (string) $session->getKey())
            ->andReturnTrue();

        $this->executor()->execute(
            new ResolvedCommand('/reset', CommandActionType::TerminateSession),
            $this->contact,
            (string) $this->assistant->getKey(),
        );

        $this->assertSame(FlowSessionStatus::TerminatedByUser, $session->fresh()->status);
    }

    public function test_reset_without_a_session_has_no_request_to_cancel(): void
    {
        $this->sessions->shouldReceive('findActiveForContact')->once()->andReturnNull();
        $this->operatorRequests->shouldNotReceive('cancelForSession');

        $this->executor()->execute(
            new ResolvedCommand('/reset', CommandActionType::TerminateSession),
            $this->contact,
            (string) $this->assistant->getKey(),
        );
    }

    public function test_a_command_that_starts_another_flow_cancels_the_request_of_the_session_it_replaces(): void
    {
        $session = $this->activeSession();
        $this->sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);
        $this->operatorRequests->shouldReceive('cancelForSession')
            ->once()
            ->with(self::TENANT_ID, (string) $this->assistant->getKey(), (string) $this->contact->getKey(), (string) $session->getKey())
            ->andReturnTrue();
        $this->definitions->shouldReceive('findLatestActiveByFlowId')->once()->andReturnNull();

        $this->executor()->execute(
            new ResolvedCommand('/menu', CommandActionType::StartFlow, flowId: (string) Str::uuid()),
            $this->contact,
            (string) $this->assistant->getKey(),
        );

        $this->assertSame(FlowSessionStatus::TerminatedByUser, $session->fresh()->status);
    }

    public function test_an_informational_command_leaves_the_session_and_its_request_alone(): void
    {
        $this->sessions->shouldNotReceive('findActiveForContact');
        $this->operatorRequests->shouldNotReceive('cancelForSession');

        $this->executor()->execute(
            new ResolvedCommand('/help', CommandActionType::SendMessage, text: 'Help'),
            $this->contact,
            (string) $this->assistant->getKey(),
        );
    }

    private function executor(): GlobalCommandExecutor
    {
        $messenger = Mockery::mock(FallbackMessageServiceInterface::class);
        $messenger->shouldIgnoreMissing();

        return $this->app->make(GlobalCommandExecutor::class, [
            'sessions'         => $this->sessions,
            'definitions'      => $this->definitions,
            'engine'           => $this->engine,
            'messenger'        => $messenger,
            'operatorRequests' => $this->operatorRequests,
        ]);
    }

    private function activeSession(): FlowSession
    {
        $flow = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Operator Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => $this->contact->getKey(),
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-op',
            'state'              => [],
            'status'             => FlowSessionStatus::WaitingInput,
            'version'            => 0,
        ]);
    }
}
