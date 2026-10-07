<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Ops\LoadTest\LoadTestSeedCommand;
use App\Console\Commands\Ops\LoadTest\Support\LoadTestFlowBlueprint;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantLimits;

/**
 * The load-test seed creates its flow through CreateFlowAction, so it keeps the blueprint's graph
 * and is subject to the same flow limit as every other creator.
 */
final class LoadTestSeedFlowTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncChannelWebhookJob::class]);
    }

    public function test_seed_creates_the_blueprint_flow_through_create_flow_action(): void
    {
        $created = $this->seedInTenant();

        $draft = FlowDraft::query()->where('flow_id', $created['flow_id'])->firstOrFail();

        $this->assertSame('Load Test Flow', $draft->name);
        // Postgres stores the graph as jsonb, which does not keep key order: compare by content.
        $this->assertEquals(LoadTestFlowBlueprint::nodes(), $draft->nodes);
        $this->assertEquals(LoadTestFlowBlueprint::edges(), $draft->edges);
        $this->assertSame(1, FlowDraft::query()->count());
    }

    public function test_seed_is_refused_by_the_flow_limit(): void
    {
        $this->app->instance(TenantLimitsInterface::class, new FakeTenantLimits(['flows' => 0]));

        $this->expectException(RecordLimitReachedException::class);

        $this->seedInTenant();
    }

    /**
     * @return array{assistant_id: string, flow_id: string, channel_id: string, channel_hash: string}
     */
    private function seedInTenant(): array
    {
        $tenant  = Tenant::query()->firstOrFail();
        $command = $this->app->make(LoadTestSeedCommand::class);
        $method  = new ReflectionMethod($command, 'createAssistantChannelAndFlow');

        $result = null;
        $this->app->make(TenantSwitcher::class)->runForTenant($tenant, function () use ($method, $command, $tenant, &$result): void {
            $result = $method->invoke($command, $tenant, 'loadtest-unit', 'bot-token', 'secret-token');
        });

        return $result;
    }
}
