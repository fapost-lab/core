<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Services\StaffRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of a staff role, for creating one (no `record` in the route) and for changing one: its name (unique per
 * guard, as the table's index is; a system role keeps its name and does not send one), a display name and the
 * permissions, each one the catalogue knows.
 */
final class RoleRequest extends FormRequest
{
    private ?Role $role = null;

    public function authorize(StaffRoleService $roles): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        $role = $this->role($roles);

        return null === $role ? $user->can('create', Role::class) : $user->can('update', $role);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(StaffRoleService $roles): array
    {
        $role = $this->role($roles);

        return [
            'name' => true === $role?->is_system
                ? ['nullable']
                : [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique(Role::class, 'name')
                        ->where('guard_name', $role?->guard_name ?? config('auth.defaults.guard'))
                        ->ignore($role?->getKey()),
                ],
            'display_name'  => ['nullable', 'string', 'max:255'],
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::values())],
        ];
    }

    /**
     * In the shape {@see \App\Domains\Staff\Services\RoleWriterService} takes. A system role's name is ignored there.
     *
     * @return array{name: string, display_name: string|null, permission_groups: array<string, list<string>>}
     */
    public function fields(): array
    {
        return [
            'name'              => (string) ($this->validated('name') ?? $this->role?->name ?? ''),
            'display_name'      => $this->filled('display_name') ? (string) $this->validated('display_name') : null,
            'permission_groups' => ['selected' => array_values(array_map('strval', (array) $this->validated('permissions')))],
        ];
    }

    private function role(StaffRoleService $roles): ?Role
    {
        $record = $this->route('record');

        if (null === $record) {
            return null;
        }

        return $this->role ??= $roles->find((string) $record);
    }
}
