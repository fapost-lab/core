<?php

declare(strict_types=1);

namespace App\Domains\Flow\Actions;

use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Handlers\EndNodeHandler;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Support\Str;

/**
 * Creates a new flow draft seeded with a terminal `end` node (status=success).
 * Authors always need a terminal — seeding it removes the empty-canvas state
 * and gives the builder a real, selectable End node to configure instead of a
 * decorative placeholder. A caller that already has a graph (the load-test seed) passes `nodes`
 * and `edges` instead.
 */
final readonly class CreateFlowAction
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private RecordQuotaInterface $recordQuota,
    ) {
    }

    /**
     * This is the only place a flow is created: the current tenant's flow limit is checked here.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RecordLimitReachedException when the tenant is at its flow limit
     */
    public function execute(array $data): FlowDraft
    {
        $this->recordQuota->assertCanCreate(FlowDraft::LIMIT_KEY, FlowDraft::countForLimit());

        return FlowDraft::create([
            'tenant_id'       => $this->tenantContext->get()->id,
            'flow_id'         => (string)Str::uuid(),
            'assistant_id'    => $data['assistant_id'],
            'flow_group_id'   => $data['flow_group_id'] ?? null,
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'is_public'       => $data['is_public'] ?? true,
            'logging_enabled' => $data['logging_enabled'] ?? false,
            'is_active'       => true,
            'nodes'           => $data['nodes'] ?? [
                [
                    'id'      => Str::lower((string)Str::ulid()),
                    'type'    => EndNodeHandler::TYPE,
                    'version' => 1,
                    'config'  => ['status' => EndStatus::Success->value],
                ],
            ],
            'edges' => $data['edges'] ?? [],
        ]);
    }
}
