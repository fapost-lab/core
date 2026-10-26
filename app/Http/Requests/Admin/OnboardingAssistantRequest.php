<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Services\TenantSettingsEditor;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Settings\TenantSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The first assistant of the setup wizard: a name and a language chosen among the languages the tenant has made
 * available (the step before this one set them).
 */
final class OnboardingAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Assistant::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TenantSettings $settings): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'default_language' => ['required', 'string', Rule::in(TenantSettingsEditor::withBase($settings->content_base_language, $settings->available_languages))],
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
        ];
    }

    /**
     * @return array{name: string, default_language: string, is_active: bool}
     */
    public function fields(): array
    {
        /** @var array{name: string, default_language: string} $validated */
        $validated = $this->validated();

        return [
            'name'             => $validated['name'],
            'default_language' => $validated['default_language'],
            'is_active'        => true,
        ];
    }
}
