<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\VariableSchemaEntry;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The variable registry keeps one row, and so one `max_size`, per contact list: a node that leaves it empty must not
 * reset what another node of the same flow declares.
 */
final class PublishListMaxSizeTest extends FeatureTestCase
{
    public function test_the_last_declared_max_size_reaches_every_declaration_of_the_same_list(): void
    {
        $tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $tenantId, schemaName: 'main'));
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $list = static fn (array $properties): array => ['variable' => [
            'name' => 'photos', 'storage' => 'contact', 'type' => 'array', 'properties' => ['item_type' => 'photo', ...$properties],
        ]];

        $draft = FlowDraft::factory()->create([
            'tenant_id'    => $tenantId,
            'assistant_id' => $assistant->getKey(),
            'nodes'        => [
                ['id' => 'ask-1', 'type' => 'input', 'version' => 1, 'config' => $list(['max_size' => 500])],
                ['id' => 'ask-2', 'type' => 'input', 'version' => 1, 'config' => $list([])],
                ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'ask-1', 'to' => 'ask-2', 'handle' => 'default'],
                ['id' => 'e2', 'from' => 'ask-2', 'to' => 'end', 'handle' => 'default'],
            ],
        ]);

        $this->app->make(PublishFlowService::class)->execute($draft->flow_id);

        $entry = VariableSchemaEntry::query()->where('tenant_id', $tenantId)->where('name', 'photos')->firstOrFail();

        $this->assertSame(500, $entry->properties['max_size'] ?? null);
    }
}
