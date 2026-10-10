<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Flow\Services\TenantSettingsEditor;
use App\Domains\Staff\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Saving the tenant's settings. The permission and every rule are the Filament page's: languages from the content
 * catalogue, at least one available language, a fallback among the available ones, the numeric floors, and a content
 * base language that may not change once the tenant has a flow definition.
 */
final class TenantSettingsRequest extends FormRequest
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
        $language = Rule::in(array_keys(ContentLanguages::options()));

        return [
            'content_base_language' => ['required', 'string', $language],
            'available_languages'   => ['required', 'array', 'list', 'min:1'],
            // A blank pick is dropped, as Filament did; what is left must not be empty (checked after).
            'available_languages.*'  => ['nullable', 'string', $language],
            'fallback_language'      => ['required', 'string', $language],
            'messaging_rate_limit'   => ['required', 'integer', 'min:1'],
            'broadcast_chunk_size'   => ['required', 'integer', 'min:1'],
            'broadcast_backpressure' => ['required', 'boolean'],
            'flow_session_ttl'       => ['required', 'integer', 'min:60'],
            'max_retry_attempts'     => ['required', 'integer', 'min:0'],
            'flow_fallback_message'  => ['nullable', 'string'],
        ];
    }

    /**
     * The checks across fields, once each field is valid on its own.
     *
     * @return list<callable(Validator): void>
     */
    public function after(TenantSettingsEditor $editor): array
    {
        return [
            function (Validator $validator) use ($editor): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $available = TenantSettingsEditor::languageList((array) $this->input('available_languages'));

                if ([] === $available) {
                    $validator->errors()->add('available_languages', trans('validation.required', ['attribute' => trans('staff.tenant_settings.fields.available_languages')]));

                    return;
                }

                if (! in_array($this->input('fallback_language'), $available, true)) {
                    $validator->errors()->add('fallback_language', trans('staff.tenant_settings.errors.fallback_not_in_available'));
                }

                if ($this->input('content_base_language') !== $editor->contentBaseLanguage() && $editor->baseLanguageLocked()) {
                    $validator->errors()->add('content_base_language', trans('staff.tenant_settings.errors.base_lang_locked'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (['content_base_language', 'available_languages', 'fallback_language', 'messaging_rate_limit', 'broadcast_chunk_size', 'broadcast_backpressure', 'flow_session_ttl', 'max_retry_attempts', 'flow_fallback_message'] as $field) {
            $attributes[$field] = trans('staff.tenant_settings.fields.' . $field);
        }

        $attributes['available_languages.*'] = $attributes['available_languages'];

        return $attributes;
    }

    /**
     * The validated form in the shape {@see TenantSettingsEditor::save()} takes.
     *
     * @return array{content_base_language: string, available_languages: list<string>, fallback_language: string, messaging_rate_limit: int, broadcast_chunk_size: int, broadcast_backpressure: bool, flow_session_ttl: int, max_retry_attempts: int, flow_fallback_message: string}
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'content_base_language'  => (string) $validated['content_base_language'],
            'available_languages'    => TenantSettingsEditor::languageList((array) $validated['available_languages']),
            'fallback_language'      => (string) $validated['fallback_language'],
            'messaging_rate_limit'   => (int) $validated['messaging_rate_limit'],
            'broadcast_chunk_size'   => (int) $validated['broadcast_chunk_size'],
            'broadcast_backpressure' => (bool) $validated['broadcast_backpressure'],
            'flow_session_ttl'       => (int) $validated['flow_session_ttl'],
            'max_retry_attempts'     => (int) $validated['max_retry_attempts'],
            'flow_fallback_message'  => (string) ($validated['flow_fallback_message'] ?? ''),
        ];
    }
}
