<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Flow\Services\TenantSettingsEditor;
use App\Domains\Staff\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The language step of the setup wizard: the language matrix of the settings screen and nothing else, under the same
 * permission and the same rules ({@see TenantLanguageRules}).
 */
final class OnboardingLanguagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true === $this->user()?->can(Permission::ManageSettings->value);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return TenantLanguageRules::rules();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(TenantSettingsEditor $editor): array
    {
        return [
            fn (Validator $validator) => TenantLanguageRules::check($validator, $this, $editor),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return TenantLanguageRules::attributes();
    }

    /**
     * @return array{base: string, available: list<string>, fallback: string}
     */
    public function languages(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'base'      => (string) $validated['content_base_language'],
            'available' => TenantSettingsEditor::languageList((array) $validated['available_languages']),
            'fallback'  => (string) $validated['fallback_language'],
        ];
    }
}
