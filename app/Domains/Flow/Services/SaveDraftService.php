<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Exceptions\InvalidTriggerPayloadException;
use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Support\Facades\DB;

final class SaveDraftService
{
    public function __construct(
        private readonly EmittedEventCollector $emittedEvents,
        private readonly TenantEventRepositoryInterface $tenantEvents,
    ) {
    }

    /**
     * @param  array<string, mixed>  $nodes
     * @param  array<string, mixed>  $edges
     * @param  mixed                 $triggers  the author's trigger list; null when the request carried none, which
     *                                          leaves the draft's list as it is. Saving never makes a trigger live:
     *                                          publishing does
     *
     * @throws InvalidTriggerPayloadException when the list is not well formed
     */
    public function execute(string $flowId, array $nodes, array $edges, mixed $triggers, int $expectedDraftVersion): int
    {
        $triggerList = null === $triggers ? null : FlowTriggerSet::fromPayload($triggers);

        return DB::transaction(function () use ($flowId, $nodes, $edges, $triggerList, $expectedDraftVersion): int {
            $draft = FlowDraft::query()
                ->where('flow_id', $flowId)
                ->where('draft_version', $expectedDraftVersion)
                ->lockForUpdate()
                ->first();

            if (null === $draft) {
                throw new DraftVersionConflictException($flowId);
            }

            // A template never starts on its own, so it has no trigger to save.
            if ($draft->isTemplate() && null !== $triggerList && [] !== $triggerList) {
                throw new InvalidTriggerPayloadException('A template has no trigger: it runs only where a flow places it.');
            }

            $draft->forceFill([
                'nodes'         => $nodes,
                'edges'         => $edges,
                'draft_version' => $draft->draft_version + 1,
            ]);

            if (null !== $triggerList) {
                $draft->forceFill(['triggers' => $triggerList]);
            }

            $draft->save();

            // Register emitted events tenant-wide already on draft save, so other
            // flows/assistants can pick them while everything is still being
            // prepared (an unpublished emitter simply never fires — that's fine).
            $this->tenantEvents->registerEventNames(
                (string) $draft->tenant_id,
                $this->emittedEvents->collect($nodes),
            );

            return $draft->draft_version;
        });
    }
}
