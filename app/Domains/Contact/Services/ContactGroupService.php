<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes and reads {@see ContactGroup}s of the current tenant.
 *
 * Groups are tenant-level, so every read and write is bounded by the tenant in {@see TenantContextInterface}: a group
 * of another tenant is "not found", never "forbidden". Membership rows go with the group (the pivot's foreign key
 * cascades on delete).
 *
 * Each write is recorded in the audit log, with the name and description as values; a group belongs to the tenant, so
 * its entry has no assistant.
 */
final readonly class ContactGroupService
{
    public function __construct(
        private TenantContextInterface $tenants,
        private AuditTrailInterface $audit,
    ) {
    }

    /**
     * The groups of the current tenant, for a list to filter, sort and page.
     *
     * @return Builder<ContactGroup>
     */
    public function query(): Builder
    {
        return ContactGroup::query()->where('tenant_id', $this->tenantId());
    }

    /**
     * @throws ModelNotFoundException when the id is not a group of the current tenant
     */
    public function findForTenant(string $id): ContactGroup
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(ContactGroup::class, [$id]);
        }

        return $this->query()->whereKey($id)->firstOrFail();
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function create(array $data): ContactGroup
    {
        return DB::transaction(function () use ($data): ContactGroup {
            $group = ContactGroup::query()->create([
                'tenant_id'   => $this->tenantId(),
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            $this->record(AuditAction::ContactGroupCreated, $group, AuditChanges::created([
                'name'        => $group->name,
                'description' => $group->description,
            ]));

            return $group;
        });
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function update(ContactGroup $group, array $data): ContactGroup
    {
        return DB::transaction(function () use ($group, $data): ContactGroup {
            $group->update([
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            $changes = AuditChanges::ofSavedModel($group, ['name', 'description']);

            if (! $changes->isEmpty()) {
                $this->record(AuditAction::ContactGroupUpdated, $group, $changes);
            }

            return $group;
        });
    }

    public function delete(ContactGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $group->delete();

            $this->record(AuditAction::ContactGroupDeleted, $group);
        });
    }

    /**
     * Deletes the listed groups that belong to the current tenant; other ids are ignored.
     *
     * A query delete: no model events fire, which is fine as nothing listens to a group's deletion and the pivot rows
     * go with it by the foreign key's cascade. The groups are read first so each one is recorded in the audit log.
     *
     * @param  list<string>  $ids
     *
     * @return int how many groups were deleted
     */
    public function deleteMany(array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => Str::isUuid($id)));

        if ([] === $ids) {
            return 0;
        }

        return DB::transaction(function () use ($ids): int {
            $groups = $this->query()->whereKey($ids)->get();

            if ($groups->isEmpty()) {
                return 0;
            }

            $count = $this->query()->whereKey($groups->modelKeys())->delete();

            foreach ($groups as $group) {
                $this->record(AuditAction::ContactGroupDeleted, $group);
            }

            return $count;
        });
    }

    private function record(AuditAction $action, ContactGroup $group, ?AuditChanges $changes = null): void
    {
        $this->audit->record(new AuditRecord(
            action: $action,
            subject: $group,
            subjectLabel: $group->name,
            changes: $changes,
        ));
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
