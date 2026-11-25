<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastOrigin;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Events\BroadcastCompleted;
use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Models\ContactTag;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Handlers\Support\AudienceMessage;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * `send_message` to tags or a segment starts a broadcast of the flow's assistant and goes on: once per node execution,
 * never while the node's previous broadcast runs, with the run's chain depth, and without throwing for a refusal.
 */
final class SendMessageBroadcastTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    private FlowDraft $draft;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));
        TenantSettings::fake(['content_base_language' => 'en', 'available_languages' => ['en', 'ru']]);
        Queue::fake([RunBroadcastJob::class]);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);
    }

    public function test_a_contactless_run_broadcasts_to_a_segment_of_the_flow_s_assistant_and_goes_on(): void
    {
        $segment = $this->vipSegmentWithOneReachableContact();
        $this->flow([
            ['id' => 'tell', 'type' => 'send_message', 'version' => 1, 'config' => [
                'content_type'      => 'text',
                'text'              => ['en' => 'Sale for {{flow.season}}', 'ru' => 'Распродажа: {{flow.season}}'],
                'addressee'         => ['kind' => 'segment', 'segment_id' => (string) $segment->getKey()],
                'broadcast_name'    => 'Sale {{flow.season}}',
                'save_broadcast_to' => 'flow.sent',
            ]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [['id' => 'e1', 'from' => 'tell', 'to' => 'end', 'handle' => 'default']]);

        $session = $this->engine()->start($this->definition, null, ['flow' => ['season' => 'winter']], FlowRunOrigin::automation((string) Str::uuid()));

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertNull($session->assistant_id, 'the run has no assistant; the broadcast takes the flow\'s');

        $broadcast = Broadcast::query()->sole();
        $this->assertSame(BroadcastStatus::Running, $broadcast->status);
        $this->assertSame(BroadcastOrigin::Automation, $broadcast->origin);
        $this->assertSame((string) $this->assistant->getKey(), $broadcast->assistant_id);
        $this->assertSame(BroadcastTarget::Segment, $broadcast->target_type);
        $this->assertSame((string) $segment->getKey(), $broadcast->target_segment_id);
        $this->assertSame(['en' => 'Sale for winter', 'ru' => 'Распродажа: winter'], $broadcast->message);
        $this->assertSame('Sale winter', $broadcast->name);
        $this->assertSame($this->draft->flow_id, $broadcast->flow_id);
        $this->assertSame((string) $session->getKey(), $broadcast->flow_session_id);
        $this->assertSame('tell', $broadcast->flow_node_id);
        $this->assertSame(1, $broadcast->chain_depth);
        $this->assertNull($broadcast->created_by);
        $this->assertSame(['broadcast_id' => (string) $broadcast->getKey(), 'recipient_count' => 1], data_get($session->state, 'flow.sent'));
        Queue::assertPushed(RunBroadcastJob::class, 1);
    }

    public function test_a_retried_step_gets_its_broadcast_back_and_another_pass_is_refused_while_it_runs(): void
    {
        $this->flow([]);
        $config = ['content_type' => 'text', 'text' => 'Hello', 'addressee' => ['kind' => 'tags', 'tags' => ['vip']]];

        $first  = $this->execute($config, 'step-1');
        $replay = $this->execute($config, 'step-1');
        $loop   = $this->execute($config, 'step-2');

        $this->assertSame('default', $first->sourceHandle);
        $this->assertSame('started', $first->metadata['broadcast_outcome']);
        $this->assertSame('default', $replay->sourceHandle);
        $this->assertSame('replayed', $replay->metadata['broadcast_outcome']);
        $this->assertSame($first->metadata['broadcast_id'], $replay->metadata['broadcast_id']);
        $this->assertSame('error', $loop->sourceHandle);
        $this->assertSame('broadcast_running', $loop->metadata['error_type']);
        $this->assertSame(1, Broadcast::query()->count());
        Queue::assertPushed(RunBroadcastJob::class, 1);

        Broadcast::query()->update(['status' => BroadcastStatus::Completed->value]);

        $next = $this->execute($config, 'step-2');

        $this->assertSame('default', $next->sourceHandle);
        $this->assertSame(2, Broadcast::query()->count(), 'once the first one is done, the next pass broadcasts again');
    }

    public function test_an_empty_audience_still_broadcasts_and_goes_on_with_no_recipients(): void
    {
        $this->flow([]);

        $result = $this->execute(['content_type' => 'text', 'text' => 'Hello', 'addressee' => ['kind' => 'tags', 'tags' => ['nobody']]], 'step-1');

        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame(0, $result->metadata['recipient_count']);
        $this->assertSame(BroadcastStatus::Running, Broadcast::query()->sole()->status);
    }

    public function test_a_refusal_takes_error_and_creates_nothing(): void
    {
        $this->flow([]);

        $cases = [
            'audience_missing' => ['content_type' => 'text', 'text' => 'Hi', 'addressee' => ['kind' => 'segment', 'segment_id' => (string) Str::uuid()]],
            'message_empty'    => ['content_type' => 'text', 'text' => ['ru' => 'Привет'], 'addressee' => ['kind' => 'tags', 'tags' => ['vip']]],
        ];

        foreach ($cases as $errorType => $config) {
            $result = $this->execute($config, "step-{$errorType}");

            $this->assertSame('error', $result->sourceHandle, $errorType);
            $this->assertSame($errorType, $result->metadata['error_type']);
        }

        $this->assertSame(0, Broadcast::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_run_without_its_flow_draft_takes_error_instead_of_throwing(): void
    {
        $this->flow([]);
        $this->draft->delete();

        $result = $this->execute(['content_type' => 'text', 'text' => 'Hi', 'addressee' => ['kind' => 'tags', 'tags' => ['vip']]], 'step-1');

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('broadcast_failure', $result->metadata['error_type']);
        $this->assertSame(0, Broadcast::query()->count());
    }

    public function test_a_run_naming_no_flow_definition_takes_error_and_creates_nothing(): void
    {
        $this->flow([]);

        $result = $this->execute(['content_type' => 'text', 'text' => 'Hi', 'addressee' => ['kind' => 'tags', 'tags' => ['vip']]], 'step-1', ['flow' => []]);

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('broadcast_failure', $result->metadata['error_type']);
        $this->assertSame(AudienceMessage::NO_FLOW_DEFINITION, $result->metadata['error']);
        $this->assertSame(0, Broadcast::query()->count());
    }

    public function test_a_language_given_as_a_number_reaches_the_broadcast_as_text(): void
    {
        $this->flow([]);

        $result = $this->execute(['content_type' => 'text', 'text' => ['en' => 42], 'addressee' => ['kind' => 'tags', 'tags' => ['nobody']]], 'step-1');

        $this->assertSame('default', $result->sourceHandle);
        $this->assertEquals(['en' => '42'], Broadcast::query()->sole()->message);
    }

    /**
     * A run four links down an event chain starts a broadcast whose completion is the fifth link: still published. The
     * listener's arithmetic past that point is pinned by `PublishCoreEventsTest`.
     */
    public function test_the_broadcast_and_its_completion_event_carry_the_run_s_chain_depth_plus_one(): void
    {
        $this->flow([
            ['id' => 'tell', 'type' => 'send_message', 'version' => 1, 'config' => [
                'content_type' => 'text',
                'text'         => 'Hi',
                'addressee'    => ['kind' => 'tags', 'tags' => ['nobody']],
            ]],
        ], []);

        $this->engine()->start($this->definition, null, [], FlowRunOrigin::automation((string) Str::uuid(), chainDepth: 4));

        $broadcast = Broadcast::query()->sole();
        $this->assertSame(5, $broadcast->chain_depth);

        Event::fake([BroadcastCompleted::class]);
        $this->app->call([new RunBroadcastJob(self::TENANT_ID, (string) $broadcast->getKey()), 'handle']);

        $this->assertSame(BroadcastStatus::Completed, $broadcast->refresh()->status);
        Event::assertDispatched(BroadcastCompleted::class, static fn (BroadcastCompleted $event): bool => 5 === $event->chainDepth);
    }

    /**
     * @param  array<string, mixed>       $config
     * @param  array<string, mixed>|null  $state  null: a run of the test's flow definition
     */
    private function execute(array $config, string $idempotencyKey, ?array $state = null): NodeExecutionResult
    {
        $handler = $this->app->make(NodeHandlerRegistryInterface::class)->resolve('send_message', 1);

        return $handler->execute(
            ['id' => 'tell', 'config' => $config],
            $state ?? ['system' => ['flow_definition_id' => (string) $this->definition->getKey()], 'flow' => []],
            new NodeExecutionContext(
                tenantId: self::TENANT_ID,
                contactId: null,
                sessionId: (string) Str::uuid(),
                nodeId: 'tell',
                idempotencyKey: $idempotencyKey,
                platform: null,
            ),
        );
    }

    private function engine(): FlowEngineInterface
    {
        return $this->app->make(FlowEngineInterface::class);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function flow(array $nodes, array $edges = []): void
    {
        $flowId = (string) Str::uuid();

        $this->draft = FlowDraft::factory()->create([
            'tenant_id'    => self::TENANT_ID,
            'flow_id'      => $flowId,
            'assistant_id' => (string) $this->assistant->getKey(),
            'name'         => 'Seasonal sale',
            'nodes'        => $nodes,
        ]);

        $this->definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Seasonal sale',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);
    }

    private function vipSegmentWithOneReachableContact(): ContactSegment
    {
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create();
        ContactTag::query()->create(['contact_id' => $contact->getKey(), 'tag' => 'vip', 'tagged_by' => null, 'tagged_at' => now()]);
        ChannelContact::query()->create(['contact_id' => $contact->getKey(), 'channel_id' => $channel->getKey(), 'last_interaction_at' => now()]);

        return ContactSegment::query()->create([
            'tenant_id' => self::TENANT_ID,
            'name'      => 'VIP',
            'rules'     => ['match' => 'all', 'conditions' => [['type' => 'tag', 'operator' => 'has', 'value' => 'vip']]],
        ]);
    }
}
