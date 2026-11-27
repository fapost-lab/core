<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Commands\GlobalCommandExecutor;
use App\Domains\Flow\Commands\ResolvedCommand;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Routing\DropPolicyInterface;
use App\Domains\Flow\Services\FlowSessionTerminator;
use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\Commands\CommandContext;
use Fapost\Foundation\Flow\Commands\CommandOutcome;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * A command whose action is a type a Solution registered runs the type's `handle()` and carries out the outcome it
 * returns ({@see GlobalCommandExecutor}).
 */
final class GlobalCommandExecutorRegisteredTypeTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private RecordingCommandType $type;

    private ReadinessAvailability $availability;

    private MockInterface&FallbackMessageServiceInterface $messenger;

    private MockInterface&FlowSessionRepositoryInterface $sessions;

    private MockInterface&FlowDefinitionRepositoryInterface $definitions;

    private MockInterface&FlowEngineInterface $engine;

    private MockInterface&OperatorRequestsInterface $operatorRequests;

    private MockInterface&DropPolicyInterface $dropPolicy;

    private MockInterface&LoggerInterface $logger;

    private MockInterface&ContentTranslatorInterface $translator;

    private Assistant $assistant;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type = new RecordingCommandType();
        $this->app->instance(RecordingCommandType::class, $this->type);
        $this->app->make(CommandActionTypeRegistry::class)->register(RecordingCommandType::class, 'feedback');

        $this->availability     = new ReadinessAvailability();
        $this->messenger        = Mockery::mock(FallbackMessageServiceInterface::class);
        $this->sessions         = Mockery::mock(FlowSessionRepositoryInterface::class);
        $this->definitions      = Mockery::mock(FlowDefinitionRepositoryInterface::class);
        $this->engine           = Mockery::mock(FlowEngineInterface::class);
        $this->operatorRequests = Mockery::mock(OperatorRequestsInterface::class);
        $this->dropPolicy       = Mockery::mock(DropPolicyInterface::class);
        $this->logger           = Mockery::mock(LoggerInterface::class);
        $this->translator       = Mockery::mock(ContentTranslatorInterface::class);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['language' => 'de']);
    }

    public function test_the_type_runs_with_the_context_of_the_message_and_its_reply_is_sent(): void
    {
        $this->type->outcome = CommandOutcome::reply('Thanks!');
        $this->sessions->shouldNotReceive('findActiveForContact');
        $this->messenger->shouldReceive('send')->once()->with($this->isContact(), $this->assistantId(), 'Thanks!');

        $this->executor()->execute($this->command(['text' => 'x']), $this->contact, $this->assistantId(), $this->assistant, 'telegram', 'update-7');

        $this->assertCount(1, $this->type->handled);
        [$context, $config] = $this->type->handled[0];
        $this->assertEquals(new CommandContext(
            command: '/feedback',
            tenantId: self::TENANT_ID,
            contactId: (string) $this->contact->getKey(),
            assistantId: $this->assistantId(),
            channelType: 'telegram',
            language: 'de',
            updateId: 'update-7',
        ), $context);
        $this->assertSame(['text' => 'x'], $config);
    }

    public function test_a_missing_channel_type_and_update_id_reach_the_type_as_empty_and_null(): void
    {
        $this->type->outcome = CommandOutcome::none();
        $this->messenger->shouldNotReceive('send');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId());

        $this->assertSame('', $this->type->handled[0][0]->channelType);
        $this->assertNull($this->type->handled[0][0]->updateId);
    }

    public function test_an_outcome_without_a_reply_sends_nothing_and_leaves_the_session_alone(): void
    {
        $this->type->outcome = CommandOutcome::none();
        $this->sessions->shouldNotReceive('findActiveForContact');
        $this->messenger->shouldNotReceive('send');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId(), $this->assistant);

        $this->assertCount(1, $this->type->handled);
    }

    public function test_an_exception_of_the_type_is_logged_and_nothing_is_sent(): void
    {
        $failure             = new RuntimeException('boom');
        $this->type->failure = $failure;
        $this->logger->shouldReceive('error')
            ->once()
            ->with('flow.command.action_type_failed', Mockery::on(static fn (array $context): bool => '/feedback' === $context['command']
                && 'feedback.record' === $context['action_type']
                && 'feedback' === $context['solution']
                && $failure === $context['exception']));
        $this->sessions->shouldNotReceive('findActiveForContact');
        $this->messenger->shouldNotReceive('send');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId(), $this->assistant);
    }

    public function test_a_solution_that_is_not_ready_does_not_run_and_the_contact_gets_the_busy_message_through_the_drop_policy(): void
    {
        $this->availability->ready = false;
        $this->dropPolicy->shouldReceive('applyBusy')->once()->with($this->isContact(), $this->assistant);
        $this->messenger->shouldNotReceive('send');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId(), $this->assistant);

        $this->assertSame([], $this->type->handled);
        $this->assertSame(['feedback'], $this->availability->asked);
    }

    public function test_without_an_assistant_the_busy_message_is_sent_directly(): void
    {
        $this->availability->ready = false;
        $this->dropPolicy->shouldNotReceive('applyBusy');
        $this->translator->shouldReceive('translate')->once()->with('errors.busy', 'de')->andReturn('Busy right now');
        $this->messenger->shouldReceive('send')->once()->with($this->isContact(), $this->assistantId(), 'Busy right now');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId());

        $this->assertSame([], $this->type->handled);
    }

    public function test_an_end_session_outcome_terminates_the_contacts_active_session_and_sends_its_reply(): void
    {
        $session             = $this->activeSession();
        $this->type->outcome = CommandOutcome::endSession('Bye');
        $this->sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);
        $this->operatorRequests->shouldReceive('cancelForSession')->once()->andReturnTrue();
        $this->messenger->shouldReceive('send')->once()->with($this->isContact(), $this->assistantId(), 'Bye');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId(), $this->assistant);

        $this->assertSame(FlowSessionStatus::TerminatedByUser, $session->fresh()->status);
    }

    public function test_an_end_session_outcome_without_a_session_only_sends_its_reply(): void
    {
        $this->type->outcome = CommandOutcome::endSession('Bye');
        $this->sessions->shouldReceive('findActiveForContact')->once()->andReturnNull();
        $this->operatorRequests->shouldNotReceive('cancelForSession');
        $this->messenger->shouldReceive('send')->once()->with($this->isContact(), $this->assistantId(), 'Bye');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId(), $this->assistant);
    }

    public function test_a_start_flow_outcome_ends_the_session_starts_the_latest_active_flow_and_sends_its_reply(): void
    {
        $session             = $this->activeSession();
        $definition          = FlowDefinition::query()->findOrFail($session->flow_definition_id);
        $this->type->outcome = CommandOutcome::startFlow('flow-to-start', 'Starting');
        $this->sessions->shouldReceive('findActiveForContact')->once()->andReturn($session);
        $this->operatorRequests->shouldReceive('cancelForSession')->once()->andReturnTrue();
        $this->definitions->shouldReceive('findLatestActiveByFlowId')->once()->with('flow-to-start')->andReturn($definition);
        $this->engine->shouldReceive('start')->once()->with($definition, $this->isContact())->andReturn($this->activeSession());
        $this->messenger->shouldReceive('send')->once()->with($this->isContact(), $this->assistantId(), 'Starting');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId(), $this->assistant);

        $this->assertSame(FlowSessionStatus::TerminatedByUser, $session->fresh()->status);
    }

    public function test_a_start_flow_outcome_for_a_flow_that_is_gone_starts_nothing(): void
    {
        $this->type->outcome = CommandOutcome::startFlow('gone');
        $this->sessions->shouldReceive('findActiveForContact')->once()->andReturnNull();
        $this->definitions->shouldReceive('findLatestActiveByFlowId')->once()->with('gone')->andReturnNull();
        $this->engine->shouldNotReceive('start');
        $this->messenger->shouldNotReceive('send');

        $this->executor()->execute($this->command(), $this->contact, $this->assistantId(), $this->assistant);
    }

    public function test_a_type_nobody_registers_any_more_does_nothing(): void
    {
        $this->messenger->shouldNotReceive('send');
        $this->dropPolicy->shouldNotReceive('applyBusy');

        $this->executor()->execute(
            new ResolvedCommand('/vacation', null, origin: 'tenant', actionType: 'hr.vacation', config: [], owner: 'hr'),
            $this->contact,
            $this->assistantId(),
            $this->assistant,
        );

        $this->assertSame([], $this->type->handled);
        $this->assertSame([], $this->availability->asked);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function command(array $config = []): ResolvedCommand
    {
        return new ResolvedCommand('/feedback', null, origin: 'solution', actionType: 'feedback.record', config: $config, owner: 'feedback');
    }

    private function assistantId(): string
    {
        return (string) $this->assistant->getKey();
    }

    private function isContact(): Mockery\Matcher\Closure
    {
        return Mockery::on(fn (mixed $given): bool => $given instanceof Contact && $given->is($this->contact));
    }

    private function executor(): GlobalCommandExecutor
    {
        return $this->app->make(GlobalCommandExecutor::class, [
            'sessions'     => $this->sessions,
            'definitions'  => $this->definitions,
            'engine'       => $this->engine,
            'messenger'    => $this->messenger,
            'terminator'   => $this->app->make(FlowSessionTerminator::class, ['operatorRequests' => $this->operatorRequests]),
            'availability' => $this->availability,
            'dropPolicy'   => $this->dropPolicy,
            'logger'       => $this->logger,
            'translator'   => $this->translator,
        ]);
    }

    private function activeSession(): FlowSession
    {
        $flow = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Registered Type Flow',
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
            'current_node_id'    => 'node-a',
            'state'              => [],
            'status'             => FlowSessionStatus::WaitingInput,
            'version'            => 0,
        ]);
    }
}

/**
 * A command action type of the Solution `feedback` that records what it ran with and returns the outcome (or throws
 * the failure) the test set.
 */
final class RecordingCommandType implements CommandActionTypeInterface
{
    public ?CommandOutcome $outcome = null;

    public ?RuntimeException $failure = null;

    /** @var list<array{0: CommandContext, 1: array<string, mixed>}> */
    public array $handled = [];

    public function type(): string
    {
        return 'feedback.record';
    }

    public function label(): string
    {
        return 'Record';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function handle(CommandContext $context, array $config): CommandOutcome
    {
        $this->handled[] = [$context, $config];

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->outcome ?? CommandOutcome::none();
    }
}

/**
 * Every Solution is available; whether it is ready is the test's choice, and the ids it was asked about are kept.
 */
final class ReadinessAvailability implements SolutionAvailabilityInterface
{
    public bool $ready = true;

    /** @var list<string> */
    public array $asked = [];

    public function isAvailable(?string $solutionId): bool
    {
        return true;
    }

    public function isReady(string $solutionId): bool
    {
        $this->asked[] = $solutionId;

        return $this->ready;
    }
}
