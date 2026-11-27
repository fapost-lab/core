<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\DTOs\AudienceBroadcastResult;
use App\Domains\Flow\Enums\AudienceBroadcastOutcome;
use App\Domains\Flow\Enums\AudienceKind;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\NotifyNodeHandler;
use App\Domains\Flow\Handlers\Support\AudienceMessage;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\EventChainContext;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Staff\Jobs\SendStaffNotificationJob;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use Carbon\Carbon;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\NodeConfigIssueSeverity;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Tests\Support\Flow\RecordingAudienceBroadcaster;
use Tests\TestCase;

/**
 * Staff mode queues a delivery job; the retired contacts mode starts a broadcast of the node's own assistant through
 * Flow's port and goes on by `default` whatever happens, saying in the trace why nothing went out.
 */
final class NotifyNodeHandlerTest extends TestCase
{
    private RecordingAudienceBroadcaster $broadcaster;

    /** @var AbstractLogger&object{records: list<array{message: string, context: array<mixed>}>} */
    private AbstractLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broadcaster = new RecordingAudienceBroadcaster();
        $this->log         = new class () extends AbstractLogger {
            /** @var list<array{message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: AudienceBroadcastOutcome}>
     */
    public static function refusals(): iterable
    {
        yield 'busy' => [AudienceBroadcastOutcome::Busy];
        yield 'audience missing' => [AudienceBroadcastOutcome::AudienceMissing];
        yield 'message empty' => [AudienceBroadcastOutcome::MessageEmpty];
    }

    public function test_staff_mode_dispatches_delivery_job_with_rendered_message_and_marks_state(): void
    {
        Bus::fake();

        $result = $this->handler()->execute(
            $this->node(['mode' => 'staff', 'target' => 'assistant', 'channel' => 'in_app', 'message' => 'Help {{flow.topic}}']),
            ['flow' => ['topic' => 'billing']],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);
        $this->assertTrue($result->stateChanges[SystemStateKeys::STAFF_NOTIFIED_PREFIX . '.node-notify']);

        Bus::assertDispatched(SendStaffNotificationJob::class, fn (SendStaffNotificationJob $job): bool => 'Help billing' === $job->message
                && ['in_app'] === $job->channels
                && 'assistant' === $job->targetConfig['target']
                && 'node-notify' === $job->nodeId);
    }

    public function test_staff_channel_all_expands_to_registered_transports(): void
    {
        Bus::fake();

        $this->handler()->execute(
            $this->node(['mode' => 'staff', 'target' => 'assistant', 'channel' => 'all', 'message' => 'x']),
            [],
            $this->context(),
        );

        Bus::assertDispatched(SendStaffNotificationJob::class, fn (SendStaffNotificationJob $job): bool => in_array('in_app', $job->channels, true) && in_array('email', $job->channels, true));
    }

    public function test_staff_does_not_dispatch_when_already_marked(): void
    {
        Bus::fake();

        $result = $this->handler()->execute(
            $this->node(['mode' => 'staff', 'target' => 'assistant', 'message' => 'x']),
            ['system' => ['staff_notified' => ['node-notify' => true]]],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame([], $result->stateChanges);
        Bus::assertNotDispatched(SendStaffNotificationJob::class);
    }

    public function test_staff_throws_on_empty_message(): void
    {
        Bus::fake();

        $this->expectException(InvalidNodeConfigException::class);

        $this->handler()->execute(
            $this->node(['mode' => 'staff', 'target' => 'assistant', 'message' => '   ']),
            [],
            $this->context(),
        );
    }

    public function test_contacts_mode_starts_a_broadcast_of_the_node_s_assistant_to_its_tags_and_marks_state(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-11-25 10:30:00');

        $result = $this->handler()->execute(
            $this->node([
                'mode'           => 'contacts',
                'assistant_id'   => 'assistant-9',
                'contact_target' => 'tag',
                'tags'           => ['vip', 'lead'],
                'message'        => ['en' => 'Promo {{flow.topic}}', 'ru' => 'Акция {{flow.topic}}'],
            ]),
            $this->state(['flow' => ['topic' => 'sale']]),
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame('contacts', $result->metadata['mode']);
        $this->assertSame('started', $result->metadata['broadcast_outcome']);
        $this->assertSame('broadcast-1', $result->metadata['broadcast_id']);
        $this->assertSame(7, $result->metadata['recipient_count']);
        $this->assertTrue($result->stateChanges[SystemStateKeys::CONTACTS_NOTIFIED_PREFIX . '.node-notify']);

        $request = $this->broadcaster->sole();
        $this->assertSame('assistant-9', $request->assistantId, 'the node\'s own assistant, not the flow\'s');
        $this->assertSame(AudienceKind::Tags, $request->audience);
        $this->assertSame(['vip', 'lead'], $request->tags);
        $this->assertNull($request->segmentId);
        $this->assertSame(['en' => 'Promo sale', 'ru' => 'Акция sale'], $request->message);
        $this->assertSame('Seasonal sale — 2026-11-25 10:30', $request->name);
        $this->assertSame('flow-1', $request->flowId);
        $this->assertSame('session-1', $request->sessionId);
        $this->assertSame('node-notify', $request->nodeId);
        $this->assertSame(hash('sha256', 'idem-1:node-notify'), $request->originKey);
        $this->assertSame(1, $request->chainDepth);

        Bus::assertNothingDispatched();
    }

    public function test_contacts_mode_to_all_names_everyone_without_tags(): void
    {
        $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'contact_target' => 'all', 'tags' => ['vip'], 'message' => 'Hi']),
            $this->state(),
            $this->context(),
        );

        $request = $this->broadcaster->sole();
        $this->assertSame(AudienceKind::All, $request->audience);
        $this->assertSame([], $request->tags);
        $this->assertSame('Hi', $request->message);
    }

    public function test_contacts_mode_does_nothing_when_already_marked(): void
    {
        Bus::fake();

        $result = $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'message' => 'x']),
            ['system' => ['contacts_notified' => ['node-notify' => true]]],
            $this->context(),
        );

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame([], $result->stateChanges);
        $this->assertSame([], $this->broadcaster->requests);
        Bus::assertNothingDispatched();
    }

    #[DataProvider('refusals')]
    public function test_contacts_mode_goes_on_by_default_when_the_broadcast_is_refused(AudienceBroadcastOutcome $outcome): void
    {
        $this->broadcaster->answer = new AudienceBroadcastResult($outcome);

        $result = $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'tags' => ['vip'], 'message' => 'Hi']),
            $this->state(),
            $this->context(),
        );

        $this->assertSame('default', $result->sourceHandle);
        $this->assertNull($result->errorMessage);
        $this->assertSame($outcome->value, $result->metadata['broadcast_outcome']);
        $this->assertTrue($result->stateChanges[SystemStateKeys::CONTACTS_NOTIFIED_PREFIX . '.node-notify']);
        $this->assertTrue($this->logged('flow.notify_contacts_not_sent', $outcome->value));
    }

    public function test_contacts_mode_goes_on_by_default_when_the_broadcast_fails(): void
    {
        $this->broadcaster->failure = new RuntimeException('database is down');

        $result = $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'tags' => ['vip'], 'message' => 'Hi']),
            $this->state(),
            $this->context(),
        );

        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame('broadcast_failure', $result->metadata['broadcast_outcome']);
        $this->assertTrue($result->stateChanges[SystemStateKeys::CONTACTS_NOTIFIED_PREFIX . '.node-notify']);
        $this->assertTrue($this->logged('flow.notify_contacts_not_sent', 'broadcast_failure'));
    }

    public function test_contacts_mode_without_a_flow_definition_in_state_is_a_failure_not_an_exception(): void
    {
        $result = $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'tags' => ['vip'], 'message' => 'Hi']),
            [],
            $this->context(),
        );

        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame('broadcast_failure', $result->metadata['broadcast_outcome']);
        $this->assertSame([], $this->broadcaster->requests);
    }

    public function test_contacts_mode_sends_nothing_when_the_message_reads_a_contact(): void
    {
        Bus::fake();

        $result = $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'tags' => ['vip'], 'message' => ['en' => 'Hi', 'ru' => 'Привет, {{ contact.name }}']]),
            $this->state(),
            $this->context(),
        );

        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame('contact_expression_refused', $result->metadata['broadcast_outcome']);
        $this->assertTrue($result->stateChanges[SystemStateKeys::CONTACTS_NOTIFIED_PREFIX . '.node-notify']);
        $this->assertSame([], $this->broadcaster->requests);
        $this->assertTrue($this->logged('flow.notify_contacts_refused', 'contact_expression_refused'));
        Bus::assertNothingDispatched();
    }

    public function test_contacts_mode_throws_without_assistant(): void
    {
        $this->expectException(InvalidNodeConfigException::class);

        $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => '', 'message' => 'Promo']),
            $this->state(),
            $this->context(),
        );
    }

    public function test_contacts_mode_throws_on_empty_message(): void
    {
        $this->expectException(InvalidNodeConfigException::class);

        $this->handler()->execute(
            $this->node(['mode' => 'contacts', 'assistant_id' => 'assistant-9', 'message' => '   ']),
            $this->state(),
            $this->context(),
        );
    }

    public function test_validation_warns_about_a_contacts_node_and_its_contact_placeholders_but_not_about_staff(): void
    {
        $handler = $this->handler();

        $this->assertSame([], $handler->validate($this->node(['mode' => 'staff', 'message' => 'Hi {{contact.name}}'])));

        $plain = $handler->validate($this->node(['mode' => 'contacts', 'assistant_id' => 'a', 'message' => 'Hi']));
        $this->assertSame(['notify_contacts_deprecated'], array_map(static fn (NodeConfigIssue $issue): string => $issue->code, $plain));
        $this->assertSame(NodeConfigIssueSeverity::Warning, $plain[0]->severity);

        $reads = $handler->validate($this->node(['mode' => 'contacts', 'assistant_id' => 'a', 'message' => 'Hi {{module.crm.name}}']));
        $this->assertSame(
            ['notify_contacts_deprecated', 'notify_contacts_contact_expression'],
            array_map(static fn (NodeConfigIssue $issue): string => $issue->code, $reads),
        );
        $this->assertSame(NodeConfigIssueSeverity::Warning, $reads[1]->severity);
    }

    private function handler(): NotifyNodeHandler
    {
        $definition = new FlowDefinition();
        $definition->forceFill(['flow_id' => 'flow-1']);

        $draft = new FlowDraft();
        $draft->forceFill(['name' => 'Seasonal sale']);

        $definitions = Mockery::mock(FlowDefinitionRepositoryInterface::class);
        $definitions->allows('findById')->with('definition-1')->andReturn($definition);

        $drafts = Mockery::mock(FlowDraftRepositoryInterface::class);
        $drafts->allows('findByFlowId')->with('flow-1')->andReturn($draft);

        return new NotifyNodeHandler(
            app(Dispatcher::class),
            app(TemplateRenderer::class),
            app(StaffNotifierRegistry::class),
            new AudienceMessage(
                $this->broadcaster,
                new EventChainContext(),
                $definitions,
                $drafts,
                app(TemplateRenderer::class),
                $this->log,
            ),
            $this->log,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function state(array $state = []): array
    {
        return [...$state, 'system' => ['flow_definition_id' => 'definition-1']];
    }

    private function logged(string $message, string $outcome): bool
    {
        foreach ($this->log->records as $record) {
            if ($message === $record['message'] && $outcome === ($record['context']['outcome'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function node(array $config): array
    {
        return ['id' => 'node-notify', 'type' => 'notify', 'config' => $config];
    }

    private function context(): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: '00000000-0000-0000-0000-000000000001',
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'node-notify',
            idempotencyKey: 'idem-1',
            platform: 'telegram',
        );
    }
}
