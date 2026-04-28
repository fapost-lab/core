<?php

declare(strict_types=1);

namespace App\Domains\Flow\Actions;

use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Str;

final readonly class CreateFlowAction
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function execute(array $data): FlowDraft
    {
        return FlowDraft::create([
            'tenant_id'     => $this->tenantContext->get()->id,
            'flow_id'       => (string)Str::uuid(),
            'assistant_id'  => $data['assistant_id'],
            'flow_group_id' => $data['flow_group_id'] ?? null,
            'name'          => $data['name'],
            'description'   => $data['description'] ?? null,
            'is_public'     => $data['is_public'] ?? true,
            'is_active'     => true,
            'nodes'         => ['nodes' => [], 'edges' => []],
        ]);
    }
}
