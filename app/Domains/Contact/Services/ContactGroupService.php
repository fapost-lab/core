<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Writes and reads {@see ContactGroup}s of the current tenant.
 *
 * Groups are tenant-level, so every read and write is bounded by the tenant in {@see TenantContextInterface}: a group
 * of another tenant is "not found", never "forbidden". Membership rows go with the group (the pivot's foreign key
 * cascades on delete).
 */
final readonly class ContactGroupService
{
    public function __construct(private TenantContextInterface $tenants)
    {
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
        return ContactGroup::query()->create([
            'tenant_id'   => $this->tenantId(),
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function update(ContactGroup $group, array $data): ContactGroup
    {
        $group->update([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return $group;
    }

    public function delete(ContactGroup $group): void
    {
        $group->delete();
    }

    /**
     * Deletes the listed groups that belong to the current tenant; other ids are ignored.
     *
     * A query delete: no model events fire, which is fine as nothing listens to a group's deletion and the pivot rows
     * go with it by the foreign key's cascade.
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

        return $this->query()->whereKey($ids)->delete();
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
