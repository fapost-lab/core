<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\DTOs\FlowLimitStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowHasLiveSessionsException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Writes and reads the {@see FlowDraft}s (the flows) of the current assistant.
 *
 * Every read and write is bounded by the tenant and the assistant, explicitly, because the console does not register
 * Filament's tenancy scope: a flow of another assistant or tenant is "not found", never "forbidden". Creating goes
 * through {@see CreateFlowAction}, the one place that checks the tenant's flow limit. Changing a flow here touches its
 * metadata only, never the graph or the draft version, which belong to the builder.
 */
final readonly class FlowDraftService
{
    /**
     * The statuses of a session that is still going: it is started or waiting for the contact, or paused by an
     * operator or by a subflow. A flow with such a session cannot be deleted; finished ones (completed, ended, failed,
     * cancelled, expired, terminated) do not hold it back.
     *
     * @var list<FlowSessionStatus>
     */
    public const array LIVE_SESSION_STATUSES = [
        FlowSessionStatus::Pending,
        FlowSessionStatus::Active,
        FlowSessionStatus::WaitingInput,
        FlowSessionStatus::Paused,
        FlowSessionStatus::PausedSubflow,
    ];

    public function __construct(
        private TenantContextInterface $tenants,
        private CurrentAssistantInterface $assistant,
        private RecordQuotaInterface $recordQuota,
        private CreateFlowAction $createFlow,
    ) {
    }

    /**
     * The flows of the current assistant for the list: with their trigger and group, the highest active published
     * version (`published_version`) and the group's name (`group_name`, to sort by).
     *
     * @return Builder<FlowDraft>
     */
    public function query(): Builder
    {
        return $this->scoped()
            ->with('trigger')
            ->addSelect([
                'published_version' => FlowDefinition::query()
                    ->selectRaw('MAX(version)')
                    ->whereColumn('flow_id', 'flow_drafts.flow_id')
                    ->where('is_active', true),
                'group_name' => FlowGroup::query()
                    ->select('name')
                    ->whereColumn('flow_groups.id', 'flow_drafts.flow_group_id'),
            ]);
    }

    /**
     * @throws ModelNotFoundException when the id is not a flow of the current assistant
     */
    public function findForAssistant(string $id): FlowDraft
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(FlowDraft::class, [$id]);
        }

        return $this->scoped()->with('assistant')->whereKey($id)->firstOrFail();
    }

    /**
     * An unsaved flow of the current assistant, to ask the policy about abilities that need a record (`update`,
     * `delete`) when only the screen's own answer is wanted. The policies look at the record's assistant, so it is set.
     */
    public function abilityProbe(): FlowDraft
    {
        return FlowDraft::query()->getModel()->newInstance()->setRelation('assistant', $this->assistant->get());
    }

    /**
     * Keeps the flows of one group. Anything that is not a group id is no filter at all.
     *
     * @param  Builder<FlowDraft>  $query
     *
     * @return bool whether the filter was applied
     */
    public function filterByGroup(Builder $query, string $groupId): bool
    {
        if (! Str::isUuid($groupId)) {
            return false;
        }

        $query->where('flow_drafts.flow_group_id', $groupId);

        return true;
    }

    /**
     * Keeps the active (`1`) or the inactive (`0`) flows. Any other value is no filter at all.
     *
     * @param  Builder<FlowDraft>  $query
     *
     * @return bool whether the filter was applied
     */
    public function filterByActive(Builder $query, string $active): bool
    {
        if ('1' !== $active && '0' !== $active) {
            return false;
        }

        $query->where('flow_drafts.is_active', '1' === $active);

        return true;
    }

    /**
     * Gathers the flows of a group next to each other, groups by name, flows without a group (or whose group is gone) last. The list's own sort
     * orders the flows inside a group.
     *
     * @param  Builder<FlowDraft>  $query
     */
    public function orderByGroup(Builder $query): void
    {
        // A flow whose group is gone (the column has no foreign key) counts as having none. An explicit CASE: where
        // NULLs sort differs between Postgres and SQLite.
        $exists = 'EXISTS (SELECT 1 FROM flow_groups WHERE flow_groups.id = flow_drafts.flow_group_id)';

        $query->orderByRaw("CASE WHEN {$exists} THEN 0 ELSE 1 END")
            ->orderBy('group_name')
            ->orderByRaw("CASE WHEN {$exists} THEN flow_drafts.flow_group_id END");
    }

    /**
     * Where the tenant stands against its flow limit (the count is tenant-wide, not per assistant).
     */
    public function limit(): FlowLimitStatus
    {
        $current = FlowDraft::countForLimit();

        return new FlowLimitStatus(
            current: $current,
            limit: $this->recordQuota->limit(FlowDraft::LIMIT_KEY),
            reached: ! $this->recordQuota->canCreate(FlowDraft::LIMIT_KEY, $current),
        );
    }

    /**
     * Creates a flow seeded with an `end` node, for the current assistant.
     *
     * @param  array{name: string, flow_group_id?: string|null, description?: string|null, is_public?: bool, logging_enabled?: bool}  $data
     *
     * @throws RecordLimitReachedException when the tenant is at its flow limit
     */
    public function create(array $data): FlowDraft
    {
        return $this->createFlow->execute([
            ...$data,
            'assistant_id' => (string) $this->assistant->get()->getKey(),
        ]);
    }

    /**
     * Changes what the list and the form show; the graph and the draft version stay as they are.
     *
     * @param  array{name: string, flow_group_id?: string|null, description?: string|null, is_public: bool, logging_enabled: bool}  $data
     */
    public function update(FlowDraft $flow, array $data): FlowDraft
    {
        $flow->update([
            'name'            => $data['name'],
            'flow_group_id'   => $data['flow_group_id'] ?? null,
            'description'     => $data['description'] ?? null,
            'is_public'       => $data['is_public'],
            'logging_enabled' => $data['logging_enabled'],
        ]);

        return $flow;
    }

    /**
     * Sets the state asked for; asking for the state a flow is already in changes nothing.
     */
    public function setActive(FlowDraft $flow, bool $active): FlowDraft
    {
        if ($flow->is_active !== $active) {
            $flow->update(['is_active' => $active]);
        }

        return $flow;
    }

    /**
     * Whether a session of the flow, on any of its published versions, is still going.
     */
    public function hasLiveSessions(FlowDraft $flow): bool
    {
        return FlowSession::query()
            ->where('tenant_id', $flow->tenant_id)
            ->whereIn('status', array_map(static fn (FlowSessionStatus $status): string => $status->value, self::LIVE_SESSION_STATUSES))
            ->whereIn('flow_definition_id', FlowDefinition::query()->select('id')->where('flow_id', $flow->flow_id))
            ->exists();
    }

    /**
     * Deletes the draft only; its published versions, triggers and finished sessions stay, as they did in Filament.
     *
     * @throws FlowHasLiveSessionsException when a session of the flow is still going
     */
    public function delete(FlowDraft $flow): void
    {
        if ($this->hasLiveSessions($flow)) {
            throw new FlowHasLiveSessionsException($flow->flow_id);
        }

        $flow->delete();
    }

    /**
     * Deletes the listed flows of the current assistant one by one, with the guard of {@see delete()}: a flow with
     * live sessions is skipped and counted, not deleted. Other ids are ignored.
     *
     * @param  list<string>  $ids
     *
     * @return array{deleted: int, blocked: int}
     */
    public function deleteMany(array $ids): array
    {
        $ids    = array_values(array_filter($ids, static fn (string $id): bool => Str::isUuid($id)));
        $result = ['deleted' => 0, 'blocked' => 0];

        if ([] === $ids) {
            return $result;
        }

        foreach ($this->scoped()->whereKey($ids)->get() as $flow) {
            try {
                $this->delete($flow);
                ++$result['deleted'];
            } catch (FlowHasLiveSessionsException) {
                ++$result['blocked'];
            }
        }

        return $result;
    }

    /**
     * @return Builder<FlowDraft>
     */
    private function scoped(): Builder
    {
        return FlowDraft::query()
            ->where('flow_drafts.tenant_id', $this->tenants->get()->getId())
            ->where('flow_drafts.assistant_id', (string) $this->assistant->get()->getKey());
    }
}
