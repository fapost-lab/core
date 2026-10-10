<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Staff\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The ids of the staff users to delete in one go. Each record is still authorized on its own when it is deleted.
 */
final class DestroyStaffUsersRequest extends FormRequest
{
    public const int MAX_IDS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('deleteAny', User::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1', 'max:' . self::MAX_IDS],
            'ids.*' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_map('strval', (array) $this->validated('ids')));
    }
}
