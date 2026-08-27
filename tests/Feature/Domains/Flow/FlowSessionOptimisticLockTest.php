<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class FlowSessionOptimisticLockTest extends FeatureTestCase
{
    public function test_save_with_optimistic_lock_throws_on_stale_version(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);
        $contact        = Contact::factory()->forTenant($tenantId)->create();
        $flowDefinition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Test Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $flowDefinition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => ['flow' => ['name' => 'welcome']],
            'status'             => FlowSessionStatus::Active,
            'version'            => 0,
        ]);

        $staleCopy = FlowSession::query()->findOrFail($session->getKey());

        $session->saveWithOptimisticLock([
            'current_node_id' => 'node-2',
        ]);

        $this->assertSame(1, $session->version);

        $this->expectException(OptimisticLockConflictException::class);

        $staleCopy->saveWithOptimisticLock([
            'current_node_id' => 'node-3',
        ]);
    }
}
