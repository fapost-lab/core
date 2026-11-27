<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Services;

use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\SegmentHandOff;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;
use Tests\Support\RecordingConnectionResolver;

final class SegmentHandOffTest extends FeatureTestCase
{
    public function test_the_hand_off_opens_its_transaction_on_the_connection_resolved_when_it_writes(): void
    {
        Queue::fake();
        $tenantId   = (string) Str::uuid();
        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Hand off',
            'nodes'     => [['id' => 'n1', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);
        $run = FlowSession::query()->forceCreate([
            'tenant_id'          => $tenantId,
            'assistant_id'       => null,
            'contact_id'         => null,
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'n1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'run_kind'           => FlowRunKind::Automation,
            'trigger_id'         => (string) Str::uuid(),
            'version'            => 1,
        ]);

        $resolver = new RecordingConnectionResolver($this->app->make('db'));
        $this->app->instance(ConnectionResolverInterface::class, $resolver);
        $this->app->forgetScopedInstances();

        $handOff = $this->app->make(SegmentHandOff::class);

        $this->assertSame([], $resolver->resolved, 'A connection captured when the hand-off is built would outlive a tenant switch.');

        $handOff->handOn($run);

        $this->assertNotEmpty($resolver->resolved);
        $this->assertSame([DB::getDefaultConnection()], array_values(array_unique($resolver->resolved)));
    }
}
