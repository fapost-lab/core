<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\NodeConfigIssueSeverity;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The settings of the current assistant that the flow runtime reads: the default language and flow, the served
 * countries, the fallback and busy messages, the slash commands and the free-form advanced settings.
 *
 * It lives in Flow, not in Assistant, because the commands, their validator and the flows they point at are Flow's,
 * and Flow already depends on Assistant (never the other way round). Every flow it reads is bounded by the tenant and
 * the assistant explicitly: no global scope does it implicitly.
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

    /** The shape of a command action type a Solution registers: "<solution id>.<snake_case>". */
    private const string REGISTERED_TYPE_PATTERN = '/^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]*$/D';

    /**
     * @param  ConnectionResolverInterface  $db  asked for its connection at the moment it writes, never kept: a tenant
     *                                         switch moves the default connection the models write through, and a
     *                                         transaction on a connection taken when this is built — before the
     *                                         switch, in a queue job — would not cover them
     */
    public function __construct(
        private TenantContextInterface $tenants,
        private CurrentAssistantInterface $assistant,
        private TenantSettings $tenantSettings,
        private CreateFlowAction $createFlow,
        private AssistantCommandsValidator $commandsValidator,
        private CommandActionTypeRegistry $commandActionTypes,
        private SolutionAvailabilityInterface $solutions,
        private AuditTrailInterface $audit,
        private ConnectionResolverInterface $db,
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
        // The settings and their audit entry commit together.
        return $this->db->connection()->transaction(fn (): Assistant => $this->write($fields, $this->languageLocked()));
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

        return $this->db->connection()->transaction(function () use ($fields, $name, $target, $commandIndex, $languageLocked): FlowDraft {
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
        return array_values($this->normalizeCommandsByPosition($commands));
    }

    /**
     * {@see normalizeCommands()}, each kept command under the key it was given at, so an error found in it can be
     * reported at the form's row even when a blank row before it was dropped.
     *
     * @param  array<array-key, mixed>  $commands
     *
     * @return array<array-key, array<string, mixed>>
     */
    public function normalizeCommandsByPosition(array $commands): array
    {
        $normalized = [];

        foreach ($commands as $position => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $command  = is_string($entry['command'] ?? null) ? mb_trim($entry['command']) : '';
            $typeName = is_string($entry['type'] ?? null) ? $entry['type'] : '';
            $type     = CommandActionType::tryFrom($typeName);

            if ('' === $command) {
                continue;
            }

            $command = str_starts_with($command, '/') ? $command : '/' . $command;

            if (null === $type) {
                // A Solution's type keeps its config; any other unknown type is dropped.
                if (1 === preg_match(self::REGISTERED_TYPE_PATTERN, $typeName)) {
                    $normalized[$position] = ['command' => $command, 'type' => $typeName, 'config' => $this->submittedConfig($command, $typeName, $entry['config'] ?? null)];
                }

                continue;
            }

            $row = [
                'command' => $command,
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

            $normalized[$position] = $row;
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
     * The stored commands whose type a Solution registered but the current tenant has not turned on: they are kept and
     * do not match until it is.
     *
     * @return list<string>
     */
    public function commandsOfInactiveSolutions(): array
    {
        $commands = [];

        foreach (is_array($this->assistant()->commands) ? $this->assistant()->commands : [] as $stored) {
            $type  = is_array($stored) && is_string($stored['type'] ?? null) ? $stored['type'] : '';
            $owner = $this->commandActionTypes->ownerOf($type);

            if (null !== $owner && ! $this->solutions->isAvailable($owner) && is_string($stored['command'] ?? null)) {
                $commands[] = $stored['command'];
            }
        }

        return $commands;
    }

    /**
     * Every command action type the form may send: the built-in ones, then the ones Solutions registered.
     *
     * @return list<string>
     */
    public function commandTypes(): array
    {
        return [
            ...array_map(static fn (CommandActionType $type): string => $type->value, CommandActionType::cases()),
            ...$this->commandActionTypes->types(),
        ];
    }

    /**
     * The command action types Solutions registered, as the form offers them: each with its label, its config form
     * ({@see CommandActionTypeInterface::configSchema()}) and whether its Solution is on for the current tenant — a
     * command of a type whose Solution is off is kept but does not run.
     *
     * @return list<array{type: string, label: string, schema: array<string, mixed>, available: bool}>
     */
    public function registeredCommandTypes(): array
    {
        $types = [];

        foreach ($this->commandActionTypes->types() as $typeName) {
            $type = $this->commandActionTypes->get($typeName);

            $types[] = [
                'type'      => $typeName,
                'label'     => $type->label(),
                'schema'    => $type->configSchema(),
                'available' => $this->solutions->isAvailable($this->commandActionTypes->ownerOf($typeName)),
            ];
        }

        return $types;
    }

    /**
     * What a Solution's type warns about in a command's config ({@see CommandActionTypeInterface::validateConfig()}
     * warnings; its errors refuse the save instead). Empty for a type nobody registers.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @return list<array{field: string|null, message: string}>
     */
    public function commandConfigWarnings(string $typeName, array $config): array
    {
        if (! $this->commandActionTypes->has($typeName)) {
            return [];
        }

        $warnings = [];

        /** @var array<string, mixed> $config */
        foreach ($this->commandActionTypes->get($typeName)->validateConfig($config) as $issue) {
            if ($issue instanceof NodeConfigIssue && NodeConfigIssueSeverity::Warning === $issue->severity) {
                $warnings[] = ['field' => $issue->field, 'message' => $issue->message];
            }
        }

        return $warnings;
    }

    /**
     * The types of the stored commands, whatever they are.
     *
     * @return list<string>
     */
    public function storedCommandTypes(): array
    {
        $types = [];

        foreach (is_array($this->assistant()->commands) ? $this->assistant()->commands : [] as $command) {
            if (is_array($command) && is_string($command['type'] ?? null)) {
                $types[] = $command['type'];
            }
        }

        return array_values(array_unique($types));
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
     * The fields a save may change, read before and after it. The texts and the free-form settings are compared here
     * and recorded by name only.
     *
     * @return array<string, mixed>
     */
    private static function auditSnapshot(Assistant $assistant): array
    {
        return [
            'default_language'    => $assistant->default_language,
            'default_flow_id'     => $assistant->default_flow_id,
            'available_countries' => $assistant->available_countries,
            'fallback_message'    => $assistant->fallback_message,
            'busy_message'        => $assistant->busy_message,
            'commands'            => $assistant->commands,
            'settings'            => $assistant->settings,
        ];
    }

    /**
     * A stored value as it reaches the request after Laravel's `TrimStrings` and `ConvertEmptyStringsToNull`, which
     * every console request passes.
     */
    private static function asSubmitted(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::asSubmitted(...), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $trimmed = Str::trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * A value with every map's keys sorted: a stored entry comes back from jsonb in its own key order.
     */
    private static function sortedKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortedKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The config of the stored command with this command and type, or null when none is stored.
     *
     * @return array<array-key, mixed>|null
     */
    private function storedConfig(string $command, string $typeName): ?array
    {
        foreach (is_array($this->assistant()->commands) ? $this->assistant()->commands : [] as $stored) {
            if (is_array($stored) && ($stored['command'] ?? null) === $command && ($stored['type'] ?? null) === $typeName) {
                return is_array($stored['config'] ?? null) ? $stored['config'] : [];
            }
        }

        return null;
    }

    /**
     * The config to keep for a command of a Solution's type. The request's copy went through the input middleware
     * (strings trimmed, empty ones as null), so each top-level field that comes back as its stored value would after
     * that is the stored field, untouched: it is kept exactly as stored, and an untouched config stays the stored one.
     * A field the form edited, or a new one, is taken as sent; the validator judges the result. A type nobody registers
     * has no editor, so its stored config is kept whatever was sent.
     *
     * @return array<array-key, mixed>
     */
    private function submittedConfig(string $command, string $typeName, mixed $sent): array
    {
        $sent   = is_array($sent) ? $sent : [];
        $stored = $this->storedConfig($command, $typeName);

        if (null === $stored) {
            return $sent;
        }

        if (! $this->commandActionTypes->has($typeName) || self::sortedKeys(self::asSubmitted($stored)) === self::sortedKeys($sent)) {
            return $stored;
        }

        foreach ($sent as $key => $value) {
            if (array_key_exists($key, $stored) && self::sortedKeys(self::asSubmitted($stored[$key])) === self::sortedKeys($value)) {
                $sent[$key] = $stored[$key];
            }
        }

        return $sent;
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

        $this->commandsValidator->validate($commands, $assistant->commands);

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

        $before = self::auditSnapshot($assistant);

        $assistant->update($attributes);

        $changes = AuditChanges::between(
            $before,
            self::auditSnapshot($assistant),
            ['default_language', 'default_flow_id', 'available_countries'],
            ['fallback_message', 'busy_message', 'commands', 'settings'],
        );

        if (! $changes->isEmpty()) {
            $this->audit->record(new AuditRecord(
                action: AuditAction::AssistantSettingsUpdated,
                subject: $assistant,
                subjectLabel: (string) $assistant->name,
                assistantId: (string) $assistant->getKey(),
                changes: $changes,
            ));
        }

        return $assistant;
    }

    /**
     * The flows the settings may point at: a template is never one of them.
     *
     * @return Builder<FlowDraft>
     */
    private function flows(): Builder
    {
        return FlowDraft::query()
            ->where('flow_drafts.kind', FlowKind::Flow->value)
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
