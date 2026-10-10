<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\StaffUserService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changes to a staff user: the profile always, the roles only when the form sent them.
 *
 * The profile needs `update` and `updateProfile` (someone else's needs a higher priority); the roles need
 * `updateRoles` for exactly the roles sent. The platform support user is refused by the Gate, administrators included.
 */
final class UpdateStaffUserRequest extends FormRequest
{
    private ?User $target = null;

    public function authorize(StaffUserService $users): bool
    {
        $actor = $this->user();

        if (! $actor instanceof User) {
            return false;
        }

        $target = $this->target($users);

        if (! $actor->can('update', $target) || ! $actor->can('updateProfile', $target)) {
            return false;
        }

        return ! $this->sendsRoles() || $actor->can('updateRoles', [$target, $this->requestedRoleIds()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(StaffUserService $users): array
    {
        /** @var User $actor */
        $actor = $this->user();

        return [
            ...StaffUserFieldRules::profile((string) $this->route('record')),
            'password' => ['nullable', 'string', 'max:255'],
            'roles'    => ['sometimes', 'array'],
            'roles.*'  => [
                'string',
                'distinct',
                Rule::in($users->assignableRoles($actor)->map(static fn (Role $role): string => (string) $role->getKey())->all()),
            ],
        ];
    }

    /**
     * @return array{name: string, email: string, phone: string|null, password: string|null}
     */
    public function profile(): array
    {
        return [
            'name'     => (string) $this->validated('name'),
            'email'    => (string) $this->validated('email'),
            'phone'    => $this->filled('phone') ? (string) $this->validated('phone') : null,
            'password' => $this->filled('password') ? (string) $this->validated('password') : null,
        ];
    }

    /**
     * The roles to set, or null when the form did not send them (the actor may not change them).
     *
     * @return list<string>|null
     */
    public function roleIds(): ?array
    {
        return $this->sendsRoles() ? array_values(array_map('strval', (array) $this->validated('roles', []))) : null;
    }

    private function sendsRoles(): bool
    {
        return $this->exists('roles');
    }

    /**
     * @return list<string>
     */
    private function requestedRoleIds(): array
    {
        $roles = $this->input('roles');

        return is_array($roles) ? array_values(array_map('strval', array_filter($roles, 'is_scalar'))) : [];
    }

    private function target(StaffUserService $users): User
    {
        return $this->target ??= $users->find((string) $this->route('record'));
    }
}
