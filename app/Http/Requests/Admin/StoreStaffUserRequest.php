<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\StaffUserService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An invitation: the profile of a new staff user and the one role they start with. The role is one the actor may give
 * (below their own priority), administrators included, as the Filament select offered.
 */
final class StoreStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(StaffUserService $users): array
    {
        /** @var User $actor */
        $actor = $this->user();

        return [
            ...StaffUserFieldRules::profile(null),
            'role_id' => [
                'required',
                'string',
                Rule::in($users->assignableRoles($actor)->map(static fn (Role $role): string => (string) $role->getKey())->all()),
            ],
        ];
    }

    /**
     * @return array{name: string, email: string, phone: ?string, role_id: string}
     */
    public function fields(): array
    {
        return [
            'name'    => (string) $this->validated('name'),
            'email'   => (string) $this->validated('email'),
            'phone'   => $this->filled('phone') ? (string) $this->validated('phone') : null,
            'role_id' => (string) $this->validated('role_id'),
        ];
    }
}
