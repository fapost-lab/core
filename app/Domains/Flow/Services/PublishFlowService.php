<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Support\Facades\DB;

final readonly class PublishFlowService
{
    public function __construct(
        private ValidateFlowService $validator,
    ) {
    }

    public function execute(string $flowId): FlowDefinition
    {
        /** @var FlowDefinition $definition */
        $definition = DB::transaction(function () use ($flowId): FlowDefinition {
            $draft = FlowDraft::query()
                ->where('flow_id', $flowId)
                ->lockForUpdate()
                ->firstOrFail();

            $result = $this->validator->execute(is_array($draft->nodes) ? $draft->nodes : []);
            if ( ! $result->valid) {
                throw new FlowValidationException($result->errors);
            }

            $lastVersion = FlowDefinition::query()
                ->where('flow_id', $draft->flow_id)
                ->max('version');

            $newVersion = ($lastVersion ?? 0) + 1;

            FlowDefinition::query()
                ->where('flow_id', $draft->flow_id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            return FlowDefinition::query()->create([
                'tenant_id'    => $draft->tenant_id,
                'flow_id'      => $draft->flow_id,
                'version'      => $newVersion,
                'name'         => $draft->name,
                'nodes'        => $draft->nodes,
                'edges'        => is_array($draft->edges) ? $draft->edges : [],
                'is_active'    => true,
                'published_at' => now(),
            ]);
        });

        return $definition;
    }
}
