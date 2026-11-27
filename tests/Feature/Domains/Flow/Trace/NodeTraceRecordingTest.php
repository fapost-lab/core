<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Trace;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Trace\NodeTraceWriter;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\ComparesJsonColumns;
use Tests\Support\RecordingConnectionResolver;

/**
 * The node trace: every node the engine runs writes `node_entered` / `node_completed` / `node_failed` rows into
 * `flow_session_history`, whatever the definition's `logging_enabled`, and never breaks the step.
 */
final class NodeTraceRecordingTest extends FeatureTestCase
{
    use ComparesJsonColumns;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(NodeHandlerRegistry::class)->register(TraceProbeTestHandler::class);
    }

    public function test_a_three_node_conversation_is_traced_without_logging_enabled(): void
    {
        $definition = $this->definition([
            $this->probe('a', 'ok'),
            $this->probe('b', 'ok'),
            ['id' => 'c', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'e1', 'from' => 'a', 'to' => 'b', 'handle' => 'default'],
            ['id' => 'e2', 'from' => 'b', 'to' => 'c', 'handle' => 'default'],
        ], loggingEnabled: false);

        $session = $this->start($definition);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame([
            ['node_entered', 'a'], ['node_completed', 'a'],
            ['node_entered', 'b'], ['node_completed', 'b'],
            ['node_entered', 'c'], ['node_completed', 'c'],
        ], $this->events($session));

        $rows = $this->rows($session);

        $this->assertSameJson(['type' => TraceProbeTestHandler::TYPE, 'version' => 1], $rows[0]->metadata);

        $completed = $rows[1]->metadata;
        $this->assertSame(TraceProbeTestHandler::TYPE, $completed['type']);
        $this->assertSame(1, $completed['version']);
        $this->assertSame(NodeExecutionStatus::Executed->value, $completed['status']);
        $this->assertSame('default', $completed['handle']);
        $this->assertSame('b', $completed['next']);
        $this->assertIsNumeric($completed['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $completed['duration_ms']);
        $this->assertArrayNotHasKey('iterations', $completed);
        $this->assertArrayNotHasKey('error', $completed);

        $last = $rows[5]->metadata;
        $this->assertSame('end', $last['type']);
        $this->assertNull($last['next']);
    }

    public function test_the_trace_row_is_written_in_a_transaction_on_the_connection_resolved_when_it_writes(): void
    {
        $session = $this->start($this->definition([$this->probe('a', 'ok')], []));

        $resolver = new RecordingConnectionResolver($this->app->make('db'));
        $this->app->instance(ConnectionResolverInterface::class, $resolver);
        $this->app->forgetScopedInstances();

        $writer = $this->app->make(NodeTraceWriter::class);

        $this->assertSame([], $resolver->resolved, 'A connection captured when the writer is built would outlive a tenant switch.');

        $writer->write(HistoryEventType::NodeEntered, $this->tenantId, (string) $session->getKey(), 'a', ['type' => 'x']);

        $this->assertNotEmpty($resolver->resolved);
        $this->assertSame([DB::getDefaultConnection()], array_values(array_unique($resolver->resolved)));
    }

    public function test_the_trace_is_written_with_logging_enabled_too_and_belongs_to_the_session_tenant(): void
    {
        $definition = $this->definition([$this->probe('a', 'ok')], [], loggingEnabled: true);

        $session = $this->start($definition);

        $this->assertSame([['node_entered', 'a'], ['node_completed', 'a']], $this->events($session));
        $this->assertSame([$this->tenantId], $this->rows($session)->pluck('tenant_id')->unique()->values()->all());
    }

    public function test_a_conversation_trace_never_carries_input_or_output(): void
    {
        $definition = $this->definition([$this->probe('a', 'payload')], []);

        $session = $this->start($definition);

        foreach ($this->rows($session) as $row) {
            $this->assertArrayNotHasKey('input', $row->metadata);
            $this->assertArrayNotHasKey('output', $row->metadata);
        }
    }

    public function test_a_conversation_trace_never_carries_payloads_even_when_the_setting_is_on(): void
    {
        Config::set('flow.trace.payloads', true);

        $session = $this->start($this->definition([$this->probe('a', 'payload')], []), FlowRunOrigin::conversation());

        $completed = $this->rows($session)->firstWhere('event_type', HistoryEventType::NodeCompleted);

        $this->assertNotNull($completed);
        $this->assertArrayNotHasKey('input', $completed->metadata);
        $this->assertArrayNotHasKey('output', $completed->metadata);
    }

    public function test_a_handler_exception_is_traced_as_failed_and_the_session_fails_as_before(): void
    {
        $definition = $this->definition([
            $this->probe('a', 'ok'),
            $this->probe('b', 'throw'),
        ], [['id' => 'e1', 'from' => 'a', 'to' => 'b', 'handle' => 'default']]);
        $contact = $this->contact();

        try {
            $this->app->make(FlowEngineInterface::class)->start($definition, $contact);
            $this->fail('The handler exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('probe exploded', $exception->getMessage());
        }

        $session = FlowSession::query()->where('flow_definition_id', $definition->getKey())->sole();

        $this->assertSame(FlowSessionStatus::Failed, $session->status);
        $this->assertSame([
            ['node_entered', 'a'], ['node_completed', 'a'],
            ['node_entered', 'b'], ['node_failed', 'b'],
        ], $this->events($session));

        $failed = $this->rows($session)->last()->metadata;

        $this->assertSame(RuntimeException::class, $failed['error']['class']);
        $this->assertSame('probe exploded', $failed['error']['message']);
        $this->assertSame(TraceProbeTestHandler::TYPE, $failed['type']);
        $this->assertSame(1, $failed['version']);
        $this->assertIsNumeric($failed['duration_ms']);
        $this->assertArrayNotHasKey('status', $failed);
    }

    public function test_a_failure_message_is_masked(): void
    {
        $definition = $this->definition([$this->probe('a', 'throw_url')], []);

        try {
            $this->app->make(FlowEngineInterface::class)->start($definition, $this->contact());
            $this->fail('The handler exception must propagate.');
        } catch (RuntimeException) {
        }

        $session = FlowSession::query()->where('flow_definition_id', $definition->getKey())->sole();
        $message = $this->rows($session)->last()->metadata['error']['message'];

        $this->assertStringContainsString('https://h.example/x?token=***', $message);
        $this->assertStringNotContainsString('abc123', $message);
    }

    public function test_a_node_that_returns_a_failed_result_is_completed_with_its_error_message(): void
    {
        $session = $this->start($this->definition([$this->probe('a', 'failed_result')], []));

        $completed = $this->rows($session)->last()->metadata;

        $this->assertSame(HistoryEventType::NodeCompleted, $this->rows($session)->last()->event_type);
        $this->assertSame(NodeExecutionStatus::Failed->value, $completed['status']);
        $this->assertSameJson(['message' => 'node said no'], $completed['error']);
    }

    public function test_a_node_of_an_unknown_type_is_traced_as_failed_and_not_completed(): void
    {
        $definition = $this->definition([['id' => 'a', 'type' => 'no_such_node_type', 'version' => 1, 'config' => []]], []);

        try {
            $this->app->make(FlowEngineInterface::class)->start($definition, $this->contact());
            $this->fail('A missing handler must fail the run.');
        } catch (RuntimeException) {
        }

        $session = FlowSession::query()->where('flow_definition_id', $definition->getKey())->sole();

        $this->assertSame([['node_entered', 'a'], ['node_failed', 'a']], $this->events($session));

        $failed = $this->rows($session)->last()->metadata;

        $this->assertSame('no_such_node_type', $failed['type']);
        $this->assertSame(1, $failed['version']);
        $this->assertArrayHasKey('class', $failed['error']);
        $this->assertArrayNotHasKey('duration_ms', $failed);
    }

    public function test_nested_loops_report_both_loop_passes_on_the_nodes_inside(): void
    {
        $definition = $this->definition([
            $this->loop('outer', 'o', 2),
            $this->loop('inner', 'n', 2),
            $this->probe('body', 'ok'),
            ['id' => 'inner_end', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'inner', 'iterator_name' => 'n']],
            ['id' => 'outer_end', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'outer', 'iterator_name' => 'o']],
            ['id' => 'done', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'e1', 'from' => 'outer', 'to' => 'inner', 'handle' => 'loop'],
            ['id' => 'e2', 'from' => 'inner', 'to' => 'body', 'handle' => 'loop'],
            ['id' => 'e3', 'from' => 'body', 'to' => 'inner_end', 'handle' => 'default'],
            ['id' => 'e4', 'from' => 'inner', 'to' => 'outer_end', 'handle' => 'default'],
            ['id' => 'e5', 'from' => 'outer', 'to' => 'done', 'handle' => 'default'],
        ]);

        $session = $this->start($definition);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);

        $bodyEntries = $this->rows($session)
            ->filter(static fn (FlowSessionHistoryEntry $row): bool => 'body' === $row->node_id && HistoryEventType::NodeEntered === $row->event_type)
            ->values();

        $this->assertCount(4, $bodyEntries);

        foreach ($bodyEntries as $entry) {
            $this->assertArrayHasKey('outer', $entry->metadata['iterations']);
            $this->assertArrayHasKey('inner', $entry->metadata['iterations']);
        }

        $this->assertSameJson(['outer' => 1, 'inner' => 1], $bodyEntries[0]->metadata['iterations']);
        $this->assertSameJson(['outer' => 1, 'inner' => 2], $bodyEntries[1]->metadata['iterations']);
        $this->assertSameJson(['outer' => 2, 'inner' => 1], $bodyEntries[2]->metadata['iterations']);
        $this->assertSameJson(['outer' => 2, 'inner' => 2], $bodyEntries[3]->metadata['iterations']);

        $bodyCompleted = $this->rows($session)
            ->first(static fn (FlowSessionHistoryEntry $row): bool => 'body' === $row->node_id && HistoryEventType::NodeCompleted === $row->event_type);

        $this->assertSameJson(['outer' => 1, 'inner' => 1], $bodyCompleted->metadata['iterations']);

        $done = $this->rows($session)->last();

        $this->assertSame('done', $done->node_id);
        $this->assertArrayNotHasKey('iterations', $done->metadata);
    }

    public function test_an_input_node_that_waits_and_is_resumed_is_traced_once_per_run(): void
    {
        $definition = $this->definition([$this->probe('ask', 'wait')], []);
        $engine     = $this->app->make(FlowEngineInterface::class);

        $session = $engine->start($definition, $this->contact());

        $this->assertSame(FlowSessionStatus::WaitingInput, $session->status);
        $this->assertSame([['node_entered', 'ask'], ['node_completed', 'ask']], $this->events($session));
        $this->assertSame(NodeExecutionStatus::Waiting->value, $this->rows($session)->last()->metadata['status']);

        $session = $engine->resume($session, new IncomingMessage(
            updateId: 'u1',
            externalUserId: 'ext',
            externalChatId: 'chat',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
        ));

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertSame([
            ['node_entered', 'ask'], ['node_completed', 'ask'],
            ['node_entered', 'ask'], ['node_completed', 'ask'],
        ], $this->events($session));
        $this->assertSame(NodeExecutionStatus::Executed->value, $this->rows($session)->last()->metadata['status']);
    }

    public function test_a_step_limit_failure_is_traced_with_the_step_limit_class_and_no_type(): void
    {
        Config::set('flow.execution.max_iterations', 3);

        $definition = $this->definition([
            $this->probe('start', 'ok'),
            $this->probe('a', 'ok'),
            $this->probe('b', 'ok'),
        ], [
            ['id' => 'e0', 'from' => 'start', 'to' => 'a', 'handle' => 'default'],
            ['id' => 'e1', 'from' => 'a', 'to' => 'b', 'handle' => 'default'],
            ['id' => 'e2', 'from' => 'b', 'to' => 'a', 'handle' => 'default'],
        ]);

        try {
            $this->app->make(FlowEngineInterface::class)->start($definition, $this->contact());
            $this->fail('Expected the step limit to fail the run.');
        } catch (FlowExecutionLimitExceededException) {
        }

        $session = FlowSession::query()->where('flow_definition_id', $definition->getKey())->sole();

        $this->assertSame(FlowSessionStatus::Failed, $session->status);

        $last = $this->rows($session)->last();

        $this->assertSame(HistoryEventType::NodeFailed, $last->event_type);
        $this->assertSame('step_limit', $last->metadata['error']['class']);
        $this->assertNotSame('', $last->metadata['error']['message']);
        $this->assertArrayNotHasKey('type', $last->metadata);
        $this->assertArrayNotHasKey('version', $last->metadata);
        $this->assertSame(1, $this->rows($session)->where('event_type', HistoryEventType::NodeFailed)->count());
    }

    public function test_a_failing_trace_write_does_not_change_the_step_outcome(): void
    {
        Log::spy();
        FlowSessionHistoryEntry::creating(static function (): void {
            throw new RuntimeException('history table unavailable');
        });

        $definition = $this->definition([
            $this->probe('a', 'ok'),
            ['id' => 'b', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [['id' => 'e1', 'from' => 'a', 'to' => 'b', 'handle' => 'default']]);

        $session = $this->start($definition);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(0, FlowSessionHistoryEntry::query()->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $context = []): bool => 'flow.trace.write_failed' === $message
                && 'a' === ($context['node_id'] ?? null)
                && 'node_entered' === ($context['event_type'] ?? null)
                && isset($context['session_id'])
                && ! array_key_exists('metadata', $context))
            ->atLeast()->once();
    }

    public function test_a_failing_trace_write_does_not_swallow_the_handlers_own_exception(): void
    {
        FlowSessionHistoryEntry::creating(static function (): void {
            throw new RuntimeException('history table unavailable');
        });

        $definition = $this->definition([$this->probe('a', 'throw')], []);

        try {
            $this->app->make(FlowEngineInterface::class)->start($definition, $this->contact());
            $this->fail('The handler exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('probe exploded', $exception->getMessage());
        }
    }

    public function test_an_automation_run_carries_input_and_output_when_the_setting_is_on(): void
    {
        Config::set('flow.trace.payloads', true);

        $session = $this->start($this->definition([$this->probe('a', 'payload')], []), FlowRunOrigin::automation());

        $completed = $this->rows($session)->firstWhere('event_type', HistoryEventType::NodeCompleted)->metadata;

        $this->assertSameJson(['node' => ['q' => 'question']], $completed['input']);
        $this->assertSame('v', $completed['output']['state_changes']['flow.x']);
        $this->assertSameJson(['m' => 1], $completed['output']['metadata']);
        $this->assertSameJson(['a' => 'answer'], $completed['output']['node']);

        $entered = $this->rows($session)->firstWhere('event_type', HistoryEventType::NodeEntered)->metadata;

        $this->assertArrayNotHasKey('input', $entered);
        $this->assertArrayNotHasKey('output', $entered);
    }

    public function test_an_automation_run_carries_no_input_or_output_when_the_setting_is_off(): void
    {
        Config::set('flow.trace.payloads', false);

        $session = $this->start($this->definition([$this->probe('a', 'payload')], []), FlowRunOrigin::automation());

        $completed = $this->rows($session)->firstWhere('event_type', HistoryEventType::NodeCompleted)->metadata;

        $this->assertArrayNotHasKey('input', $completed);
        $this->assertArrayNotHasKey('output', $completed);
        $this->assertSame(NodeExecutionStatus::Executed->value, $completed['status']);
    }

    public function test_an_automation_payload_is_masked(): void
    {
        $session = $this->start($this->definition([$this->probe('a', 'secrets')], []), FlowRunOrigin::automation());

        $completed = $this->rows($session)->firstWhere('event_type', HistoryEventType::NodeCompleted)->metadata;

        $this->assertSame('***', $completed['output']['node']['headers']['Authorization']);
        $this->assertSame('https://h.example/x?token=***', $completed['output']['node']['url']);
        $this->assertSame('visible', $completed['output']['node']['note']);
    }

    public function test_the_message_an_automation_node_resumed_on_is_part_of_its_input(): void
    {
        $definition = $this->definition([$this->probe('ask', 'wait')], []);
        $engine     = $this->app->make(FlowEngineInterface::class);
        $session    = $engine->start($definition, $this->contact(), [], FlowRunOrigin::automation());

        $session = $engine->resume($session, new IncomingMessage(
            updateId: 'u1',
            externalUserId: 'ext',
            externalChatId: 'chat',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
        ));

        $completed = $this->rows($session)->last()->metadata;

        $this->assertSameJson(['message' => ['type' => 'text', 'text' => 'hello']], $completed['input']);
    }

    public function test_what_a_contact_types_to_an_auth_request_never_reaches_the_trace(): void
    {
        Config::set('flow.trace.payloads', true);
        $this->app->make(NodeHandlerRegistry::class)->register(AuthRequestTraceProbeTestHandler::class);

        $definition = $this->definition([['id' => 'auth', 'type' => 'auth_request', 'version' => AuthRequestTraceProbeTestHandler::VERSION, 'config' => []]], []);
        $engine     = $this->app->make(FlowEngineInterface::class);
        $session    = $engine->start($definition, $this->contact(), [], FlowRunOrigin::automation());

        $session = $engine->resume($session, new IncomingMessage(
            updateId: 'u1',
            externalUserId: 'ext',
            externalChatId: 'chat',
            text: '482913',
            type: IncomingMessageType::Text,
            platform: 'telegram',
        ));

        $completed = $this->rows($session)->last()->metadata;

        $this->assertSameJson(['message' => ['type' => 'text']], $completed['input']);
        $this->assertStringNotContainsString('482913', (string) json_encode($this->rows($session)->pluck('metadata')->all()));
    }

    public function test_deleting_a_contact_deletes_the_trace_rows_of_its_sessions(): void
    {
        $contact = $this->contact();
        $session = $this->app->make(FlowEngineInterface::class)->start($this->definition([$this->probe('a', 'ok')], []), $contact);

        $this->assertSame(2, FlowSessionHistoryEntry::query()->where('session_id', $session->getKey())->count());

        $other = $this->start($this->definition([$this->probe('a', 'ok')], []));

        $contact->delete();

        $this->assertSame(0, FlowSessionHistoryEntry::query()->where('session_id', $session->getKey())->count());
        $this->assertSame(2, FlowSessionHistoryEntry::query()->where('session_id', $other->getKey())->count());
    }

    private function start(FlowDefinition $definition, ?FlowRunOrigin $origin = null): FlowSession
    {
        return $this->app->make(FlowEngineInterface::class)->start($definition, $this->contact(), [], $origin);
    }

    private function contact(): Contact
    {
        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        return Contact::factory()->forTenant($this->tenantId)->create();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function definition(array $nodes, array $edges, bool $loggingEnabled = false): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id'       => $this->tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Node trace',
            'nodes'           => $nodes,
            'edges'           => $edges,
            'is_active'       => true,
            'logging_enabled' => $loggingEnabled,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(string $id, string $action): array
    {
        return ['id' => $id, 'type' => TraceProbeTestHandler::TYPE, 'version' => 1, 'config' => ['action' => $action]];
    }

    /**
     * @return array<string, mixed>
     */
    private function loop(string $id, string $iterator, int $count): array
    {
        return ['id' => $id, 'type' => 'loop', 'version' => 1, 'config' => [
            'mode'          => 'counted',
            'iterator_name' => $iterator,
            'count_source'  => ['type' => 'literal', 'value' => $count],
        ]];
    }

    /**
     * @return \Illuminate\Support\Collection<int, FlowSessionHistoryEntry>
     */
    private function rows(FlowSession $session): \Illuminate\Support\Collection
    {
        return FlowSessionHistoryEntry::query()
            ->where('session_id', $session->getKey())
            ->whereIn('event_type', [HistoryEventType::NodeEntered->value, HistoryEventType::NodeCompleted->value, HistoryEventType::NodeFailed->value])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function events(FlowSession $session): array
    {
        return $this->rows($session)
            ->map(static fn (FlowSessionHistoryEntry $row): array => [$row->event_type->value, $row->node_id])
            ->values()
            ->all();
    }
}

final class TraceProbeTestHandler implements NodeHandlerInterface
{
    public const string TYPE = 'trace_probe_test';

    public function type(): string
    {
        return self::TYPE;
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Trace Probe Test';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $action = $nodeConfig['config']['action'] ?? 'ok';

        return match ($action) {
            'throw'         => throw new RuntimeException('probe exploded'),
            'throw_url'     => throw new RuntimeException('request to https://h.example/x?token=abc123 failed'),
            'failed_result' => NodeExecutionResult::failed('node said no'),
            'wait'          => null === $context->incoming ? NodeExecutionResult::waiting() : NodeExecutionResult::executed(),
            'payload'       => new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
                stateChanges: ['flow.x' => 'v'],
                metadata: ['m' => 1],
                trace: ['input' => ['q' => 'question'], 'output' => ['a' => 'answer']],
            ),
            'secrets' => new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
                trace: ['output' => [
                    'headers' => ['Authorization' => 'Bearer s3cr3t'],
                    'url'     => 'https://h.example/x?token=abc',
                    'note'    => 'visible',
                ]],
            ),
            default => NodeExecutionResult::executed(),
        };
    }
}

/**
 * Stands in for `auth_request` at a version of its own: waits, then completes on the next message.
 */
final class AuthRequestTraceProbeTestHandler implements NodeHandlerInterface
{
    public const int VERSION = 99;

    public function type(): string
    {
        return 'auth_request';
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [self::VERSION];
    }

    public function label(): string
    {
        return 'Auth request probe';
    }

    public function category(): string
    {
        return 'Contact';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return null === $context->incoming ? NodeExecutionResult::waiting() : NodeExecutionResult::executed();
    }
}
