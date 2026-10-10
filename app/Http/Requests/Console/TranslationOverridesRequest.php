<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Staff\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The overrides of one system translation key, by language, on either panel's translations screen. Every language of
 * the tenant is sent: an empty (or missing) value removes that language's override, so the cell falls back to the next
 * layer. Values under other languages are ignored when stored.
 */
final class TranslationOverridesRequest extends FormRequest
{
    /**
     * The permission both Filament pages asked for; the assistant in the URL is already guarded by the console stack.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return null !== $user && $user->can(Permission::ManageTranslations->value);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'values' => ['present', 'array'],
            // `*_translations.value` is `text`; the bound keeps a request from storing a document.
            // An empty string reaches validation as null (the middleware converts it that way): it clears the override.
            'values.*' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function values(): array
    {
        /** @var array<array-key, string|null> $values */
        $values     = $this->validated('values');
        $byLanguage = [];

        foreach ($values as $language => $value) {
            $byLanguage[(string) $language] = $value;
        }

        return $byLanguage;
    }
}
