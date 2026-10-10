<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The profile fields of a staff user, shared by the invitation and the edit form. The email is unique, as the
 * table's unique index is, and never the address reserved for the platform support user.
 */
final class StaffUserFieldRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function profile(?string $ignoreId): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($ignoreId),
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && PlatformSupportUserService::isReservedEmail($value)) {
                        $fail(__('staff.support_access.reserved_email'));
                    }
                },
            ],
        ];
    }
}
