<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Flow\Exceptions\FlowGroupNotEmptyException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes and reads the {@see FlowGroup}s of the current assistant.
 *
 * A group belongs to one assistant of one tenant, and every read and write is bounded by both: a group of another
 * assistant or tenant is "not found", never "forbidden". No global scope bounds a query implicitly, so the bounds are
 * explicit here. A group that still holds flows cannot be deleted: `flow_drafts.flow_group_id` has no foreign key to
 * catch it, so this is the only guard.
 */
final readonly class FlowGroupService
{
    public function __construct(
        private TenantContextInterface $tenants,
        private CurrentAssistantInterface $assistant,
        private AuditTrailInterface $audit,
    ) {
    }

    /**
     * The groups of the current assistant, for a list to filter, sort and page.
     *
     * @return Builder<FlowGroup>
     */
    public function query(): Builder
    {
        return FlowGroup::query()
            ->where('tenant_id', $this->tenantId())
            ->where('assistant_id', $this->assistantId());
    }

    /**
     * @throws ModelNotFoundException when the id is not a group of the current assistant
     */
    public function findForAssistant(string $id): FlowGroup
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(FlowGroup::class, [$id]);
        }

        return $this->query()->whereKey($id)->firstOrFail();
    }

    /**
     * Every group of the assistant by name, for a select.
     *
     * @return list<array{id: string, name: string}>
     */
    public function options(): array
    {
        return $this->query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (FlowGroup $group): array => ['id' => (string) $group->getKey(), 'name' => $group->name])
            ->values()
            ->all();
    }

    /**
     * @param  array{name: string}  $data
     */
    public function create(array $data): FlowGroup
    {
        return DB::transaction(function () use ($data): FlowGroup {
            $group = FlowGroup::query()->create([
                'tenant_id'    => $this->tenantId(),
                'assistant_id' => $this->assistantId(),
                'name'         => $data['name'],
            ]);

            $this->audit->record(new AuditRecord(
                action: AuditAction::FlowGroupCreated,
                subject: $group,
                subjectLabel: $group->name,
                assistantId: $group->assistant_id,
                changes: AuditChanges::created(['name' => $group->name]),
            ));

            return $group;
        });
    }

    /**
     * @param  array{name: string}  $data
     */
    public function update(FlowGroup $group, array $data): FlowGroup
    {
        DB::transaction(function () use ($group, $data): void {
            $group->update(['name' => $data['name']]);

            $changes = AuditChanges::ofSavedModel($group, ['name']);

            if ($changes->isEmpty()) {
                return;
            }

            $this->audit->record(new AuditRecord(
                action: AuditAction::FlowGroupUpdated,
                subject: $group,
                subjectLabel: $group->name,
                assistantId: $group->assistant_id,
                changes: $changes,
            ));
        });

        return $group;
    }

    /**
     * @throws FlowGroupNotEmptyException when the group still has flows
     */
    public function delete(FlowGroup $group): void
    {
        if ($this->hasFlows($group)) {
            throw new FlowGroupNotEmptyException((string) $group->getKey());
        }

        DB::transaction(function () use ($group): void {
            $group->delete();

            $this->audit->record(new AuditRecord(
                action: AuditAction::FlowGroupDeleted,
                subject: $group,
                subjectLabel: $group->name,
                assistantId: $group->assistant_id,
            ));
        });
    }

    /**
     * Deletes the listed groups of the current assistant one by one, with the guard of {@see delete()}: a group that
     * still has flows is skipped and counted, not deleted. Other ids are ignored.
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

        foreach ($this->query()->whereKey($ids)->get() as $group) {
            try {
                $this->delete($group);
                ++$result['deleted'];
            } catch (FlowGroupNotEmptyException) {
                ++$result['blocked'];
            }
        }

        return $result;
    }

    private function hasFlows(FlowGroup $group): bool
    {
        return FlowDraft::query()->where('flow_group_id', $group->getKey())->exists();
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }

    private function assistantId(): string
    {
        return (string) $this->assistant->get()->getKey();
    }
}
