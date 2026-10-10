<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Support\CountryCatalog;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\AssistantSettingsService;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a flow from inside the settings form: the whole form, as {@see AssistantSettingsRequest} takes it, plus the
 * new flow's name and what it is for (the default flow, or the `start_flow` command at `command_index`, which may
 * not have a flow yet). Needs the settings permission and the right to create a flow; the flow limit is the service's.
 */
final class StoreAssistantSettingsFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return null !== $user
            && $user->can(Permission::ManageAssistantSettings->value)
            && $user->can('create', FlowDraft::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(AssistantSettingsService $settings, TenantContextInterface $tenants, CurrentAssistantInterface $assistant, CountryCatalog $countries): array
    {
        return [
            ...AssistantSettingsRules::rules($this, $settings, $tenants, $assistant, $countries, $this->commandIndex()),
            'new_flow_name'   => ['required', 'string', 'max:255'],
            'new_flow_target' => ['required', 'string', Rule::in([AssistantSettingsService::TARGET_DEFAULT, AssistantSettingsService::TARGET_COMMAND])],
            'command_index'   => ['exclude_unless:new_flow_target,' . AssistantSettingsService::TARGET_COMMAND, 'required', 'integer', 'min:0'],
        ];
    }

    /**
     * The command a flow is made for must be in the form and start a flow.
     *
     * @return list<callable(Validator): void>
     */
    public function after(AssistantSettingsService $settings, AssistantCommandsValidator $commandsValidator): array
    {
        return [
            function (Validator $instance): void {
                $index = $this->commandIndex();

                if (null === $index || $instance->errors()->has('command_index')) {
                    return;
                }

                if (CommandActionType::StartFlow->value !== $this->input("commands.{$index}.type")) {
                    $instance->errors()->add('command_index', trans('console.settings.errors.command_not_start_flow'));
                }
            },
            ...AssistantSettingsRules::after($this, $settings, $commandsValidator, $this->commandIndex()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...AssistantSettingsRules::attributes(),
            'new_flow_name' => trans('console.settings.new_flow.name'),
        ];
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

    public function flowName(): string
    {
        return (string) $this->validated('new_flow_name');
    }

    /**
     * @return AssistantSettingsService::TARGET_*
     */
    public function target(): string
    {
        return AssistantSettingsService::TARGET_COMMAND === $this->validated('new_flow_target')
            ? AssistantSettingsService::TARGET_COMMAND
            : AssistantSettingsService::TARGET_DEFAULT;
    }

    /**
     * The command the flow is for, when it is for a command.
     */
    public function commandIndex(): ?int
    {
        if (AssistantSettingsService::TARGET_COMMAND !== $this->input('new_flow_target')) {
            return null;
        }

        $index = filter_var($this->input('command_index'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return false === $index ? null : $index;
    }

    protected function prepareForValidation(): void
    {
        AssistantSettingsRules::dropEmptySettingsRows($this);
    }
}
