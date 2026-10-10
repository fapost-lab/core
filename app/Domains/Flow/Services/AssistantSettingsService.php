<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The settings of the current assistant that the flow runtime reads: the default language and flow, the served
 * countries, the fallback and busy messages, the slash commands and the free-form advanced settings.
 *
 * It lives in Flow, not in Assistant, because the commands, their validator and the flows they point at are Flow's,
 * and Flow already depends on Assistant (never the other way round). Every flow it reads is bounded by the tenant and
 * the assistant explicitly: the console registers no Filament tenancy scope.
 *
 * What it stores is canonical, as the Filament page stored it: a command has only the fields its type uses, blank
 * locale texts are dropped, and the default language stays as it is once the assistant has flows (their content was
 * written against it).
 */
final readonly class AssistantSettingsService
{
    /** A new flow made from the form becomes the assistant's default flow. */
    public const string TARGET_DEFAULT = 'default';

    /** A new flow made from the form is the flow a `start_flow` command starts. */
    public const string TARGET_COMMAND = 'command';

    public function __construct(
        private TenantContextInterface $tenants,
        private CurrentAssistantInterface $assistant,
        private TenantSettings $tenantSettings,
        private CreateFlowAction $createFlow,
        private AssistantCommandsValidator $commandsValidator,
    ) {
    }

    public function assistant(): Assistant
    {
        return $this->assistant->get();
    }

    /**
     * Whether the default language can no longer change: the assistant already has flows.
     */
    public function languageLocked(): bool
    {
        return $this->flows()->exists();
    }

    /**
     * The languages the localized texts have a tab for: English first, then the tenant's languages, then any language
     * a stored text still has, so nothing stored is hidden from the form.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $assistant = $this->assistant();
        $stored    = [];

        foreach ([$assistant->fallback_message, $assistant->busy_message] as $field) {
            $stored = [...$stored, ...$this->localesOf($field)];
        }

        foreach (is_array($assistant->commands) ? $assistant->commands : [] as $command) {
            if (is_array($command)) {
                $stored = [...$stored, ...$this->localesOf($command['response'] ?? null), ...$this->localesOf($command['text'] ?? null)];
            }
        }

        $locales = ['en', ...$this->tenantSettings->available_languages, ...$stored];

        return array_values(array_unique(array_filter($locales, static fn (mixed $locale): bool => is_string($locale) && '' !== $locale)));
    }

    /**
     * The flows a form can choose: the active ones, and the ones the settings already point at even when switched
     * off, so a stored choice is shown rather than lost. By name.
     *
     * @return list<array{value: string, label: string, active: bool}>
     */
    public function flowOptions(): array
    {
        $chosen = $this->chosenFlowIds();

        return $this->flows()
            ->where(static function (Builder $query) use ($chosen): void {
                $query->where('is_active', true)->orWhereIn('flow_id', $chosen);
            })
            ->orderBy('name')
            ->get(['flow_id', 'name', 'is_active'])
            ->map(static fn (FlowDraft $flow): array => [
                'value'  => (string) $flow->flow_id,
                'label'  => (string) $flow->name,
                'active' => (bool) $flow->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * The flow ids of this assistant among the given ones (a deleted flow, or another assistant's, is not).
     *
     * @param  list<string>  $flowIds
     *
     * @return list<string>
     */
    public function existingFlowIds(array $flowIds): array
    {
        if ([] === $flowIds) {
            return [];
        }

        return $this->flows()->whereIn('flow_id', $flowIds)->pluck('flow_id')->map(static fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * Writes the settings as the form sent them, in their canonical shape.
     *
     * @param  array{default_language?: string|null, available_countries?: list<string>|null, default_flow_id?: string|null, fallback_message?: mixed, busy_message?: mixed, commands?: list<array<string, mixed>>|null, settings?: list<array{key: string, value?: string|null}>|null}  $fields
     *
     * @throws InvalidArgumentException when the commands break a rule of {@see AssistantCommandsValidator}
     */
    public function save(array $fields): Assistant
    {
        return $this->write($fields, $this->languageLocked());
    }

    /**
     * Creates a flow from inside the form and saves the settings with it chosen, together: as the default flow, or as
     * the flow of the `start_flow` command at `$commandIndex`. When the flow limit refuses, nothing is written. The
     * default language is judged as it stood before the new flow, as the form was validated.
     *
     * @param  array{default_language?: string|null, available_countries?: list<string>|null, default_flow_id?: string|null, fallback_message?: mixed, busy_message?: mixed, commands?: list<array<string, mixed>>|null, settings?: list<array{key: string, value?: string|null}>|null}  $fields
     * @param  self::TARGET_*  $target
     *
     * @throws RecordLimitReachedException when the tenant is at its flow limit
     * @throws InvalidArgumentException    when the target command is missing or the commands break a rule
     */
    public function saveWithNewFlow(array $fields, string $name, string $target, ?int $commandIndex = null): FlowDraft
    {
        $languageLocked = $this->languageLocked();

        return DB::transaction(function () use ($fields, $name, $target, $commandIndex, $languageLocked): FlowDraft {
            $flow = $this->createFlow->execute([
                'assistant_id' => (string) $this->assistant()->getKey(),
                'name'         => $name,
                'is_public'    => true,
            ]);

            if (self::TARGET_DEFAULT === $target) {
                $fields['default_flow_id'] = $flow->flow_id;
            } else {
                if (null === $commandIndex || ! is_array($fields['commands'][$commandIndex] ?? null)) {
                    throw new InvalidArgumentException('The command the new flow is for is missing.');
                }

                $fields['commands'][$commandIndex]['flow_id'] = $flow->flow_id;
            }

            $this->write($fields, $languageLocked);

            return $flow;
        });
    }

    /**
     * Commands as stored: the command trimmed and starting with `/`, only the fields its type uses (a response for
     * `terminate_session`, a flow for `start_flow`, a text for `send_message`), blank rows and unknown types dropped.
     *
     * @param  array<array-key, mixed>  $commands
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeCommands(array $commands): array
    {
        $normalized = [];

        foreach ($commands as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $command = is_string($entry['command'] ?? null) ? mb_trim($entry['command']) : '';
            $type    = CommandActionType::tryFrom(is_string($entry['type'] ?? null) ? $entry['type'] : '');

            if ('' === $command || null === $type) {
                continue;
            }

            $row = [
                'command' => str_starts_with($command, '/') ? $command : '/' . $command,
                'type'    => $type->value,
            ];

            if (CommandActionType::TerminateSession === $type && null !== ($response = $this->cleanLocalized($entry['response'] ?? null))) {
                $row['response'] = $response;
            }

            if (CommandActionType::StartFlow === $type && null !== ($flowId = $this->nonBlank($entry['flow_id'] ?? null))) {
                $row['flow_id'] = $flowId;
            }

            if (CommandActionType::SendMessage === $type && null !== ($text = $this->cleanLocalized($entry['text'] ?? null))) {
                $row['text'] = $text;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * A localized text as stored in the commands: a non-blank legacy string, or a locale map with at least one
     * non-blank text; null when nothing meaningful is left.
     *
     * @return string|array<string, string>|null
     */
    public function cleanLocalized(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return '' === mb_trim($value) ? null : $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $clean = [];

        foreach ($value as $locale => $text) {
            if (is_string($locale) && is_string($text) && '' !== mb_trim($text)) {
                $clean[$locale] = $text;
            }
        }

        return [] === $clean ? null : $clean;
    }

    /**
     * A stored localized text as a locale map for the form. A legacy plain string goes to the first locale: a map with
     * one language resolves to that text in every language, as the string did.
     *
     * @return array<string, string>
     */
    public function asLocaleMap(mixed $value): array
    {
        if (is_string($value)) {
            return '' === mb_trim($value) ? [] : [$this->locales()[0] => $value];
        }

        $map = [];

        foreach (is_array($value) ? $value : [] as $locale => $text) {
            if (is_string($locale) && is_scalar($text)) {
                $map[$locale] = (string) $text;
            }
        }

        return $map;
    }

    /**
     * The advanced settings as the form's list of key and value. A value that is not a string is shown as JSON, as a
     * key-value editor can only hold strings; saving keeps it as stored unless its row was changed.
     *
     * @return list<array{key: string, value: string}>
     */
    public function settingsRows(): array
    {
        $rows = [];

        foreach ($this->storedSettings() as $key => $value) {
            $rows[] = ['key' => (string) $key, 'value' => $this->displayed($value)];
        }

        return $rows;
    }

    /**
     * The flows the stored settings point at: the default flow and the flows of `start_flow` commands.
     *
     * @return list<string>
     */
    public function chosenFlowIds(): array
    {
        $assistant = $this->assistant();
        $ids       = is_string($assistant->default_flow_id) && '' !== $assistant->default_flow_id ? [$assistant->default_flow_id] : [];

        foreach (is_array($assistant->commands) ? $assistant->commands : [] as $command) {
            if (is_array($command) && is_string($command['flow_id'] ?? null) && '' !== $command['flow_id']) {
                $ids[] = $command['flow_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array{default_language?: string|null, available_countries?: list<string>|null, default_flow_id?: string|null, fallback_message?: mixed, busy_message?: mixed, commands?: list<array<string, mixed>>|null, settings?: list<array{key: string, value?: string|null}>|null}  $fields
     *
     * @throws InvalidArgumentException when the commands break a rule of {@see AssistantCommandsValidator}
     */
    private function write(array $fields, bool $languageLocked): Assistant
    {
        $assistant = $this->assistant();
        $commands  = $this->normalizeCommands($fields['commands'] ?? []);

        $this->commandsValidator->validate($commands);

        $attributes = [
            'available_countries' => array_values(array_unique(array_map(
                static fn (string $code): string => mb_strtoupper($code),
                $fields['available_countries'] ?? [],
            ))),
            'default_flow_id'  => $this->nonBlank($fields['default_flow_id'] ?? null),
            'fallback_message' => $this->localizedMap($fields['fallback_message'] ?? null),
            'busy_message'     => $this->localizedMap($fields['busy_message'] ?? null),
            'commands'         => $commands,
            'settings'         => $this->settingsMap($fields['settings'] ?? []),
        ];

        // A locked language is not a field of the form any more, whatever was sent.
        if (! $languageLocked && null !== $this->nonBlank($fields['default_language'] ?? null)) {
            $attributes['default_language'] = (string) $fields['default_language'];
        }

        $assistant->update($attributes);

        return $assistant;
    }

    /**
     * @return Builder<FlowDraft>
     */
    private function flows(): Builder
    {
        return FlowDraft::query()
            ->where('flow_drafts.tenant_id', $this->tenants->get()->getId())
            ->where('flow_drafts.assistant_id', (string) $this->assistant()->getKey());
    }

    /**
     * @return list<string>
     */
    private function localesOf(mixed $field): array
    {
        return is_array($field) ? array_values(array_filter(array_keys($field), 'is_string')) : [];
    }

    /**
     * The fallback and busy messages are locale maps only; nothing left is null (the runtime then uses its own text).
     *
     * @return array<string, string>|null
     */
    private function localizedMap(mixed $value): ?array
    {
        $clean = $this->cleanLocalized(is_array($value) ? $value : null);

        return is_array($clean) ? $clean : null;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     *
     * @return array<string, mixed>
     */
    private function settingsMap(array $rows): array
    {
        $stored = $this->storedSettings();
        $map    = [];

        foreach ($rows as $row) {
            $key = is_array($row) && is_string($row['key'] ?? null) ? mb_trim($row['key']) : '';

            if ('' === $key) {
                continue;
            }

            $value = is_string($row['value'] ?? null) ? $row['value'] : '';

            // A row left as the form showed it keeps the stored value with its type (a number, a flag, a nested
            // object, null); only a changed or new row becomes the text that was typed.
            $map[$key] = array_key_exists($key, $stored) && $this->displayed($stored[$key]) === $value ? $stored[$key] : $value;
        }

        return $map;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function storedSettings(): array
    {
        $settings = $this->assistant()->settings;

        return is_array($settings) ? $settings : [];
    }

    /**
     * How a stored value shows in the key-value editor, which holds only text: a string as it is, null as empty,
     * anything else as JSON.
     */
    private function displayed(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            null === $value   => '',
            default           => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    private function nonBlank(mixed $value): ?string
    {
        return is_string($value) && '' !== mb_trim($value) ? $value : null;
    }
}
