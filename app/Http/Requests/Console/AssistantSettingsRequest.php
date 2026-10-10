<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Support\CountryCatalog;
use App\Domains\Flow\Services\AssistantSettingsService;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Saving the assistant's settings form. The rules are {@see AssistantSettingsRules}; the permission is the one the
 * Filament page asked for.
 */
final class AssistantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true === $this->user()?->can(Permission::ManageAssistantSettings->value);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(AssistantSettingsService $settings, TenantContextInterface $tenants, CurrentAssistantInterface $assistant, CountryCatalog $countries): array
    {
        return AssistantSettingsRules::rules($this, $settings, $tenants, $assistant, $countries);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(AssistantSettingsService $settings, AssistantCommandsValidator $commandsValidator): array
    {
        return AssistantSettingsRules::after($this, $settings, $commandsValidator);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return AssistantSettingsRules::attributes();
    }

    /**
     * @return array{default_language?: string|null, available_countries?: list<string>|null, default_flow_id?: string|null, fallback_message?: mixed, busy_message?: mixed, commands?: list<array<string, mixed>>|null, settings?: list<array{key: string, value?: string|null}>|null}
     */
    public function fields(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return AssistantSettingsRules::fields($validated);
    }

    protected function prepareForValidation(): void
    {
        AssistantSettingsRules::dropEmptySettingsRows($this);
    }
}
