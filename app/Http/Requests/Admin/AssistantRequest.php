<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Staff\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of the admin assistant form, for creating an assistant (no `record` in the route) and for changing one:
 * the name, the default content language and the active flag, as the Filament form had them. The tenant's languages
 * and the assistant's other settings live on their own screens.
 */
final class AssistantRequest extends FormRequest
{
    /**
     * Changing needs `update` on the assistant in the route (one the user may not see is a 404), creating needs `create`.
     */
    public function authorize(StaffAssistantService $assistants): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        if (null === $this->route('record')) {
            return $user->can('create', Assistant::class);
        }

        return $user->can('update', $assistants->findFor($user, (string) $this->route('record')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'default_language' => ['required', 'string', Rule::in(array_keys(ContentLanguages::options()))],
            'is_active'        => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name'             => trans('console.assistants.fields.name'),
            'default_language' => trans('console.assistants.fields.default_language'),
            'is_active'        => trans('console.assistants.fields.is_active'),
        ];
    }

    /**
     * @return array{name: string, default_language: string, is_active: bool}
     */
    public function fields(): array
    {
        /** @var array{name: string, default_language: string, is_active: bool|int|string} $validated */
        $validated = $this->validated();

        return [
            'name'             => $validated['name'],
            'default_language' => $validated['default_language'],
            'is_active'        => (bool) $validated['is_active'],
        ];
    }
}
