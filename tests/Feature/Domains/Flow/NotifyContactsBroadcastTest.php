<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastOrigin;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * A `notify` node published in the retired contacts mode sends through Broadcasting: a broadcast of the node's own
 * assistant, once per node execution, never while the node's previous one runs, and `default` whatever happens.
 */
final class NotifyContactsBroadcastTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $nodeAssistant;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));
        TenantSettings::fake(['content_base_language' => 'en', 'available_languages' => ['en', 'ru']]);
        Queue::fake([RunBroadcastJob::class]);

        $flowAssistant       = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->nodeAssistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $flowId              = (string) Str::uuid();

        FlowDraft::factory()->create([
            'tenant_id'    => self::TENANT_ID,
            'flow_id'      => $flowId,
            'assistant_id' => (string) $flowAssistant->getKey(),
            'name'         => 'Seasonal sale',
            'nodes'        => [],
        ]);

        $this->definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Seasonal sale',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    public function test_a_tags_node_starts_an_automation_broadcast_of_its_own_assistant(): void
    {
        $result = $this->execute(['contact_target' => 'tag', 'tags' => ['vip'], 'message' => ['en' => 'Sale', 'ru' => 'Распродажа']], 'step-1');

        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame('started', $result->metadata['broadcast_outcome']);

        $broadcast = Broadcast::query()->sole();
        $this->assertSame((string) $broadcast->getKey(), $result->metadata['broadcast_id']);
        $this->assertSame(BroadcastOrigin::Automation, $broadcast->origin);
        $this->assertSame(BroadcastStatus::Running, $broadcast->status);
        $this->assertSame((string) $this->nodeAssistant->getKey(), $broadcast->assistant_id);
        $this->assertSame(BroadcastTarget::Tags, $broadcast->target_type);
        $this->assertSame(['vip'], $broadcast->target_tags);
        $this->assertSame(['en' => 'Sale', 'ru' => 'Распродажа'], $broadcast->message);
        $this->assertSame($this->definition->flow_id, $broadcast->flow_id);
        $this->assertSame('notify-1', $broadcast->flow_node_id);
        $this->assertSame(1, $broadcast->chain_depth);
        $this->assertStringStartsWith('Seasonal sale — ', $broadcast->name);
        Queue::assertPushed(RunBroadcastJob::class, 1);
    }

    public function test_an_all_node_broadcasts_to_everyone(): void
    {
        $result = $this->execute(['contact_target' => 'all', 'message' => 'Hello'], 'step-1');

        $this->assertSame('started', $result->metadata['broadcast_outcome']);
        $this->assertSame(BroadcastTarget::All, Broadcast::query()->sole()->target_type);
        $this->assertSame(['en' => 'Hello'], Broadcast::query()->sole()->message);
    }

    public function test_a_retried_step_gets_its_broadcast_back_and_another_session_is_refused_while_it_runs(): void
    {
        $config = ['contact_target' => 'tag', 'tags' => ['vip'], 'message' => 'Hello'];

        $first   = $this->execute($config, 'step-1');
        $replay  = $this->execute($config, 'step-1');
        $another = $this->execute($config, 'step-2');

        $this->assertSame('started', $first->metadata['broadcast_outcome']);
        $this->assertSame('replayed', $replay->metadata['broadcast_outcome']);
        $this->assertSame($first->metadata['broadcast_id'], $replay->metadata['broadcast_id']);
        $this->assertSame('default', $another->sourceHandle, 'notify@1 has one exit: a refusal goes on');
        $this->assertSame('broadcast_running', $another->metadata['broadcast_outcome']);
        $this->assertSame(1, Broadcast::query()->count());
    }

    public function test_a_run_naming_no_flow_definition_goes_on_with_a_broadcast_failure(): void
    {
        $result = $this->execute(['contact_target' => 'tag', 'tags' => ['vip'], 'message' => 'Hello'], 'step-1', ['flow' => []]);

        $this->assertSame('default', $result->sourceHandle);
        $this->assertSame('broadcast_failure', $result->metadata['broadcast_outcome']);
        $this->assertSame(0, Broadcast::query()->count());
    }

    /**
     * @param  array<string, mixed>       $config
     * @param  array<string, mixed>|null  $state  null: a run of the test's flow definition
     */
    private function execute(array $config, string $idempotencyKey, ?array $state = null): NodeExecutionResult
    {
        $handler = $this->app->make(NodeHandlerRegistryInterface::class)->resolve('notify', 1);

        return $handler->execute(
            ['id' => 'notify-1', 'config' => ['mode' => 'contacts', 'assistant_id' => (string) $this->nodeAssistant->getKey(), ...$config]],
            $state ?? ['system' => ['flow_definition_id' => (string) $this->definition->getKey()], 'flow' => []],
            new NodeExecutionContext(
                tenantId: self::TENANT_ID,
                contactId: 'contact-1',
                sessionId: (string) Str::uuid(),
                nodeId: 'notify-1',
                idempotencyKey: $idempotencyKey,
                platform: 'telegram',
            ),
        );
    }
}
