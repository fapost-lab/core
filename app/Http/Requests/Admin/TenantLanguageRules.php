<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Flow\Services\TenantSettingsEditor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules of the tenant's language matrix (content base, available, fallback), shared by the settings screen
 * ({@see TenantSettingsRequest}) and the setup wizard ({@see OnboardingLanguagesRequest}) so the two cannot drift:
 * languages from the content catalogue, at least one available language, a fallback among the available ones, and a
 * content base language that may not change once the tenant has a flow definition.
 */
final class TenantLanguageRules
{
    public const array FIELDS = ['content_base_language', 'available_languages', 'fallback_language'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        $language = Rule::in(array_keys(ContentLanguages::options()));

        return [
            'content_base_language' => ['required', 'string', $language],
            'available_languages'   => ['required', 'array', 'list', 'min:1'],
            // A blank pick is dropped, as Filament did; what is left must not be empty (checked after).
            'available_languages.*' => ['nullable', 'string', $language],
            'fallback_language'     => ['required', 'string', $language],
        ];
    }

    /**
     * The checks across the three fields, once each is valid on its own.
     */
    public static function check(Validator $validator, Request $request, TenantSettingsEditor $editor): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $available = TenantSettingsEditor::languageList((array) $request->input('available_languages'));

        if ([] === $available) {
            $validator->errors()->add('available_languages', trans('validation.required', ['attribute' => trans('staff.tenant_settings.fields.available_languages')]));

            return;
        }

        if (! in_array($request->input('fallback_language'), $available, true)) {
            $validator->errors()->add('fallback_language', trans('staff.tenant_settings.errors.fallback_not_in_available'));
        }

        if ($request->input('content_base_language') !== $editor->contentBaseLanguage() && $editor->baseLanguageLocked()) {
            $validator->errors()->add('content_base_language', trans('staff.tenant_settings.errors.base_lang_locked'));
        }
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $attributes = [];

        foreach (self::FIELDS as $field) {
            $attributes[$field] = trans('staff.tenant_settings.fields.' . $field);
        }

        $attributes['available_languages.*'] = $attributes['available_languages'];

        return $attributes;
    }
}
