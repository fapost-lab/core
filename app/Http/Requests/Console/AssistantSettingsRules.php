<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Assistant\Support\CountryCatalog;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Services\AssistantSettingsService;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The rules of the assistant's settings form, shared by the request that saves it and the request that saves it while
 * creating a flow, so both accept the same settings.
 *
 * They repeat what the Filament page held a value to (the offered languages, countries and flows, a command of at most
 * 64 characters, a flow for `start_flow`); the commands, once normalized, must pass the domain's
 * {@see AssistantCommandsValidator}, whose refusal is reported on `commands`. A flow must be one of this assistant's:
 * the columns have no foreign key to hold them to that.
 */
final class AssistantSettingsRules
{
    /**
     * @param  int|null  $awaitingFlow  the command a new flow is being made for: it starts a flow but has none yet
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(
        Request $request,
        AssistantSettingsService $settings,
        TenantContextInterface $tenants,
        CurrentAssistantInterface $assistant,
        CountryCatalog $countries,
        ?int $awaitingFlow = null,
    ): array {
        $flow = Rule::exists('flow_drafts', 'flow_id')
            ->where('tenant_id', $tenants->get()->getId())
            ->where('assistant_id', (string) $assistant->get()->getKey());

        $rules = [
            // A locked language is not validated: it is not saved either.
            'default_language'      => $settings->languageLocked() ? ['nullable'] : ['required', 'string', Rule::in(array_keys(ContentLanguages::options()))],
            'available_countries'   => ['nullable', 'array'],
            'available_countries.*' => ['string', 'distinct', Rule::in(array_keys($countries->options()))],
            'default_flow_id'       => ['nullable', 'string', $flow],
            'fallback_message'      => ['nullable', 'array'],
            'fallback_message.*'    => ['nullable', 'string'],
            'busy_message'          => ['nullable', 'array'],
            'busy_message.*'        => ['nullable', 'string'],
            'commands'              => ['nullable', 'array', 'list'],
            'commands.*'            => ['array'],
            'commands.*.command'    => ['required', 'string', 'max:64'],
            'commands.*.type'       => ['required', 'string', Rule::enum(CommandActionType::class)],
            'commands.*.flow_id'    => ['nullable', 'string', $flow],
            'commands.*.response'   => ['nullable', 'array'],
            'commands.*.response.*' => ['nullable', 'string'],
            'commands.*.text'       => ['nullable', 'array'],
            'commands.*.text.*'     => ['nullable', 'string'],
            'settings'              => ['nullable', 'array', 'list'],
            'settings.*'            => ['array'],
            'settings.*.key'        => ['required', 'string', 'max:255', 'distinct'],
            'settings.*.value'      => ['nullable', 'string', 'max:65535'],
        ];

        foreach (self::commands($request) as $index => $command) {
            $startsFlow = is_array($command) && CommandActionType::StartFlow->value === ($command['type'] ?? null);

            $rules["commands.{$index}.flow_id"] = [Rule::requiredIf($startsFlow && $index !== $awaitingFlow), 'nullable', 'string', $flow];
        }

        return $rules;
    }

    /**
     * Drops the advanced-settings rows with neither key nor value, as Filament's key-value editor did, so an empty row
     * added and left is not an error. Called from each request's `prepareForValidation()`.
     */
    public static function dropEmptySettingsRows(Request $request): void
    {
        $rows = $request->input('settings');

        if (! is_array($rows)) {
            return;
        }

        $blank = static fn (mixed $value): bool => null === $value || (is_string($value) && '' === mb_trim($value));

        $request->merge(['settings' => array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => ! is_array($row) || ! $blank($row['key'] ?? null) || ! $blank($row['value'] ?? null),
        ))]);
    }

    /**
     * Checks that need more than one field: a localized text only in a language the form has a tab for, and the
     * commands against the domain's rules once every per-field rule has passed.
     *
     * @return list<callable(Validator): void>
     */
    public static function after(Request $request, AssistantSettingsService $settings, AssistantCommandsValidator $validator, ?int $awaitingFlow = null): array
    {
        return [
            static function (Validator $instance) use ($request, $settings): void {
                $locales = $settings->locales();
                $paths   = ['fallback_message', 'busy_message'];

                foreach (array_keys(self::commands($request)) as $index) {
                    $paths[] = "commands.{$index}.response";
                    $paths[] = "commands.{$index}.text";
                }

                foreach ($paths as $path) {
                    $value = $request->input($path);

                    foreach (is_array($value) ? $value : [] as $locale => $text) {
                        // A blank text in a language without a tab is dropped when saving, so it is no error.
                        if (! in_array((string) $locale, $locales, true) && is_string($text) && '' !== mb_trim($text)) {
                            $instance->errors()->add("{$path}.{$locale}", trans('console.settings.errors.unknown_language'));
                        }
                    }
                }
            },
            static function (Validator $instance) use ($request, $settings, $validator, $awaitingFlow): void {
                if ($instance->errors()->isNotEmpty()) {
                    return;
                }

                $commands = $settings->normalizeCommands(self::commands($request));

                // The command a new flow is made for gets it when saving; until then a placeholder stands in for it.
                if (null !== $awaitingFlow && isset($commands[$awaitingFlow])) {
                    $commands[$awaitingFlow]['flow_id'] = 'new';
                }

                try {
                    $validator->validate($commands);
                } catch (InvalidArgumentException $exception) {
                    $instance->errors()->add('commands', trans('console.settings.errors.commands_invalid', ['error' => $exception->getMessage()]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'default_language'      => trans('console.settings.fields.default_language'),
            'available_countries'   => trans('console.settings.fields.available_countries'),
            'available_countries.*' => trans('console.settings.fields.available_countries'),
            'default_flow_id'       => trans('console.settings.fields.default_flow'),
            'fallback_message.*'    => trans('console.settings.fields.fallback_message'),
            'busy_message.*'        => trans('console.settings.fields.busy_message'),
            'commands.*.command'    => trans('console.settings.commands.fields.command'),
            'commands.*.type'       => trans('console.settings.commands.fields.type'),
            'commands.*.flow_id'    => trans('console.settings.commands.fields.flow'),
            'commands.*.response.*' => trans('console.settings.commands.fields.response'),
            'commands.*.text.*'     => trans('console.settings.commands.fields.text'),
            'settings.*.key'        => trans('console.settings.fields.settings_key'),
            'settings.*.value'      => trans('console.settings.fields.settings_value'),
        ];
    }

    /**
     * The validated settings in the shape {@see AssistantSettingsService::save()} takes.
     *
     * @param  array<string, mixed>  $validated
     *
     * @return array{default_language?: string|null, available_countries?: list<string>|null, default_flow_id?: string|null, fallback_message?: mixed, busy_message?: mixed, commands?: list<array<string, mixed>>|null, settings?: list<array{key: string, value?: string|null}>|null}
     */
    public static function fields(array $validated): array
    {
        /** @var array{default_language?: string|null, available_countries?: list<string>|null, default_flow_id?: string|null, fallback_message?: mixed, busy_message?: mixed, commands?: list<array<string, mixed>>|null, settings?: list<array{key: string, value?: string|null}>|null} $fields */
        $fields = [
            'default_language'    => $validated['default_language'] ?? null,
            'available_countries' => array_values(is_array($validated['available_countries'] ?? null) ? $validated['available_countries'] : []),
            'default_flow_id'     => $validated['default_flow_id'] ?? null,
            'fallback_message'    => $validated['fallback_message'] ?? null,
            'busy_message'        => $validated['busy_message'] ?? null,
            'commands'            => self::inOrder($validated['commands'] ?? null),
            'settings'            => self::inOrder($validated['settings'] ?? null),
        ];

        return $fields;
    }

    /**
     * A validated list in the order it was sent: `validated()` rebuilds it rule by rule, and a rule written for one
     * position (`commands.1.flow_id`) puts that row first.
     *
     * @return list<mixed>
     */
    private static function inOrder(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function commands(Request $request): array
    {
        $commands = $request->input('commands');

        return is_array($commands) ? $commands : [];
    }
}
