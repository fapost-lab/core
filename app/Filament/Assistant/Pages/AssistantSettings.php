<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Pages;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Assistant\Support\CountryCatalog;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Flows\FlowResource;
use App\Filament\Support\LocalizedTextarea;
use App\Filament\Support\RecordLimit;
use BackedEnum;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use UnitEnum;

/**
 * Assistant settings page — default language, default flow, fallback message and advanced settings JSON.
 */
final class AssistantSettings extends Page
{
    /** @var array<string, mixed>|null */
    public ?array            $data                          = [];
    protected static ?string $slug                          = 'settings';
    protected static ?int    $navigationSort                = 50;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;
    protected string         $view                          = 'filament.assistant.pages.assistant-settings';

    protected CurrentAssistantInterface $currentAssistant;

    private ?RecordLimit $flowLimit = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permission::ManageAssistantSettings->value);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('assistant.pages.settings.title');
    }

    public function getTitle(): string
    {
        return __('assistant.pages.settings.title');
    }

    public function boot(CurrentAssistantInterface $currentAssistant): void
    {
        $this->currentAssistant = $currentAssistant;
    }

    public function mount(): void
    {
        $assistantData = $this->currentAssistant->get()->only([
            'default_language',
            'available_countries',
            'default_flow_id',
            'fallback_message',
            'busy_message',
            'commands',
            'settings',
        ]);

        $assistantData['available_countries'] = is_array($assistantData['available_countries'] ?? null)
            ? $assistantData['available_countries']
            : [];

        // Filament Repeater expects a list-shaped array; an unset / null commands
        // column reads back as null which would crash the input cast.
        $assistantData['commands'] = is_array($assistantData['commands'] ?? null)
            ? $assistantData['commands']
            : [];

        $this->form->fill($assistantData);
    }

    public function save(AssistantCommandsValidator $validator): void
    {
        if (! $this->persist($validator)) {
            return;
        }

        Notification::make()
            ->success()
            ->title(__('assistant.pages.settings.saved'))
            ->send();
    }

    public function form(Schema $schema): Schema
    {
        $assistant = $this->currentAssistant->get();
        $hasFlows  = FlowDraft::query()->where('assistant_id', $assistant->id)->exists();

        $flowOptions = FlowDraft::query()
            ->where('assistant_id', $assistant->id)
            ->where('is_active', true)
            ->pluck('name', 'flow_id')
            ->toArray();

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('assistant.pages.settings.tabs.general'))
                            ->icon(Heroicon::OutlinedCog6Tooth)
                            ->schema([
                                Select::make('default_language')
                                    ->label(__('assistant.pages.settings.fields.default_language'))
                                    ->options(ContentLanguages::options())
                                    ->searchable()
                                    ->nullable()
                                    ->disabled($hasFlows)
                                    ->hintIcon($hasFlows ? Heroicon::OutlinedLockClosed : null)
                                    ->hintIconTooltip(
                                        $hasFlows ? __('assistant.pages.settings.fields.default_language_locked') : null
                                    ),
                                Select::make('available_countries')
                                    ->label(__('assistant.pages.settings.fields.available_countries'))
                                    ->helperText(__('assistant.pages.settings.fields.available_countries_help'))
                                    ->options(app(CountryCatalog::class)->options())
                                    ->multiple()
                                    ->searchable()
                                    ->nullable(),
                                Select::make('default_flow_id')
                                    ->label(__('assistant.pages.settings.fields.default_flow_id'))
                                    ->options($flowOptions)
                                    ->nullable()
                                    ->searchable()
                                    ->suffixAction(
                                        Action::make('createDefaultFlow')
                                            ->label(__('assistant.pages.settings.fields.default_flow_create'))
                                            ->icon(Heroicon::OutlinedPlus)
                                            ->modalHeading(__('assistant.pages.settings.fields.default_flow_create_modal'))
                                            ->modalSubmitActionLabel(__('assistant.pages.settings.fields.default_flow_create_submit'))
                                            ->schema([
                                                TextInput::make('name')
                                                    ->label(__('assistant.flows.fields.name'))
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->autofocus(),
                                            ])
                                            ->visible(fn (): bool => ! $this->flowLimit()->reached)
                                            ->action(function (array $data, Set $set): void {
                                                // Persist current form first — the redirect leaves the page
                                                // before the user can press Save and any pending edits would
                                                // otherwise be lost.
                                                if (! $this->persist(app(AssistantCommandsValidator::class))) {
                                                    return;
                                                }

                                                $assistant = $this->currentAssistant->get();

                                                try {
                                                    $flow = app(CreateFlowAction::class)->execute([
                                                        'assistant_id' => (string) $assistant->getKey(),
                                                        'name'         => $data['name'],
                                                        'is_public'    => true,
                                                    ]);
                                                } catch (RecordLimitReachedException $e) {
                                                    RecordLimit::notifyReached($e, 'assistant.flows.limit');

                                                    return;
                                                }

                                                // The new flow becomes the default, overriding whatever was
                                                // selected before the modal opened.
                                                $assistant->update(['default_flow_id' => $flow->flow_id]);
                                                $set('default_flow_id', $flow->flow_id);

                                                $this->redirect(url("/builder/flows/{$flow->flow_id}"));
                                            }),
                                    ),
                                LocalizedTextarea::tabs(
                                    statePath: 'fallback_message',
                                    label: __('assistant.pages.settings.fields.fallback_message'),
                                ),
                                LocalizedTextarea::tabs(
                                    statePath: 'busy_message',
                                    label: __('assistant.pages.settings.fields.busy_message'),
                                    helperText: __('assistant.pages.settings.fields.busy_message_help'),
                                ),
                            ]),

                        Tab::make(__('assistant.pages.settings.tabs.commands'))
                            ->icon(Heroicon::OutlinedCommandLine)
                            ->badge(fn (Get $get): ?int => is_array($commands = $get('commands')) && [] !== $commands ? count($commands) : null)
                            ->schema([
                                Repeater::make('commands')
                                    ->hiddenLabel()
                                    ->helperText(__('assistant.pages.settings.fields.commands_help'))
                                    ->itemLabel(fn (array $state): ?string => is_string($state['command'] ?? null) ? $state['command'] : null)
                                    ->addActionLabel(__('assistant.pages.settings.commands.add_label'))
                                    ->reorderable(false)
                                    ->collapsible()
                                    ->default([])
                                    ->schema([
                                        TextInput::make('command')
                                            ->label(__('assistant.pages.settings.commands.fields.command'))
                                            ->required()
                                            ->maxLength(64)
                                            ->prefix('/')
                                            ->placeholder('start')
                                            ->dehydrateStateUsing(static function (?string $state): string {
                                                $value = mb_trim((string) $state);
                                                if ('' === $value) {
                                                    return '';
                                                }

                                                return str_starts_with($value, '/') ? $value : '/' . $value;
                                            }),
                                        Select::make('type')
                                            ->label(__('assistant.pages.settings.commands.fields.type'))
                                            ->options([
                                                CommandActionType::TerminateSession->value => __('assistant.pages.settings.commands.types.terminate_session'),
                                                CommandActionType::StartFlow->value        => __('assistant.pages.settings.commands.types.start_flow'),
                                                CommandActionType::SendMessage->value      => __('assistant.pages.settings.commands.types.send_message'),
                                            ])
                                            ->helperText(static fn (Get $get): ?string => match ($get('type')) {
                                                CommandActionType::TerminateSession->value => __('assistant.pages.settings.commands.types_help.terminate_session'),
                                                CommandActionType::StartFlow->value        => __('assistant.pages.settings.commands.types_help.start_flow'),
                                                CommandActionType::SendMessage->value      => __('assistant.pages.settings.commands.types_help.send_message'),
                                                default                                    => null,
                                            })
                                            ->required()
                                            ->live()
                                            ->default(CommandActionType::TerminateSession->value),
                                        // Per-locale "acknowledgement" texts. Only emitted for
                                        // terminate_session — start_flow lets the started flow speak,
                                        // send_message uses its own `text` field instead.
                                        LocalizedTextarea::tabs(
                                            statePath: 'response',
                                            label: __('assistant.pages.settings.commands.fields.response'),
                                            rows: 2,
                                            tabsKey: 'localized_response',
                                        )->visible(static fn (Get $get): bool => CommandActionType::TerminateSession->value === $get('type')),
                                        Select::make('flow_id')
                                            ->label(__('assistant.pages.settings.commands.fields.flow_id'))
                                            ->options($flowOptions)
                                            ->searchable()
                                            ->required(static fn (Get $get): bool => CommandActionType::StartFlow->value === $get('type'))
                                            ->visible(static fn (Get $get): bool => CommandActionType::StartFlow->value === $get('type'))
                                            ->suffixAction(
                                                Action::make('createCommandFlow')
                                                    ->label(__('assistant.pages.settings.fields.default_flow_create'))
                                                    ->icon(Heroicon::OutlinedPlus)
                                                    ->modalHeading(__('assistant.pages.settings.fields.default_flow_create_modal'))
                                                    ->modalSubmitActionLabel(__('assistant.pages.settings.fields.default_flow_create_submit'))
                                                    ->schema([
                                                        TextInput::make('name')
                                                            ->label(__('assistant.flows.fields.name'))
                                                            ->required()
                                                            ->maxLength(255)
                                                            ->autofocus(),
                                                    ])
                                                    ->visible(fn (): bool => ! $this->flowLimit()->reached)
                                                    ->action(function (array $data): void {
                                                        // Persist current form first — pending edits to other
                                                        // commands and settings would be lost on redirect.
                                                        if (! $this->persist(app(AssistantCommandsValidator::class))) {
                                                            return;
                                                        }

                                                        $assistant = $this->currentAssistant->get();

                                                        try {
                                                            $flow = app(CreateFlowAction::class)->execute([
                                                                'assistant_id' => (string) $assistant->getKey(),
                                                                'name'         => $data['name'],
                                                                'is_public'    => true,
                                                            ]);
                                                        } catch (RecordLimitReachedException $e) {
                                                            RecordLimit::notifyReached($e, 'assistant.flows.limit');

                                                            return;
                                                        }

                                                        $this->redirect(url("/builder/flows/{$flow->flow_id}"));
                                                    }),
                                            ),
                                        // Per-locale message body for send_message commands.
                                        LocalizedTextarea::tabs(
                                            statePath: 'text',
                                            label: __('assistant.pages.settings.commands.fields.text'),
                                            rows: 2,
                                            tabsKey: 'localized_text',
                                        )->visible(static fn (Get $get): bool => CommandActionType::SendMessage->value === $get('type')),
                                    ]),
                            ]),

                        Tab::make(__('assistant.pages.settings.tabs.advanced'))
                            ->icon(Heroicon::OutlinedCodeBracket)
                            ->schema([
                                KeyValue::make('settings')
                                    ->label(__('assistant.pages.settings.fields.settings'))
                                    ->nullable(),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('assistant.pages.settings.actions.save'))
                ->action('save'),
        ];
    }

    /**
     * Read once per request: every create-flow suffix action shares one count.
     */
    private function flowLimit(): RecordLimit
    {
        return $this->flowLimit ??= FlowResource::limit();
    }

    /**
     * Validate + write current form state to the assistant. Used both by the
     * explicit Save action and by the inline "create flow" suffix actions —
     * the latter must not lose pending edits before redirecting to the builder.
     */
    private function persist(AssistantCommandsValidator $validator): bool
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $data['commands'] = $this->normalizeCommands(is_array($data['commands'] ?? null) ? $data['commands'] : []);

        try {
            $validator->validate($data['commands']);
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->danger()
                ->title(__('assistant.pages.settings.errors.commands_invalid', ['error' => $exception->getMessage()]))
                ->send();

            return false;
        }

        $this->currentAssistant->get()->update($data);

        return true;
    }

    /**
     * Strip type-irrelevant fields and drop blank rows so the JSON column
     * stays canonical (no orphan flow_id on terminate_session, etc.).
     *
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private function normalizeCommands(array $commands): array
    {
        $normalized = [];

        foreach ($commands as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $command = is_string($entry['command'] ?? null) ? mb_trim((string) $entry['command']) : '';
            $type    = CommandActionType::tryFrom((string) ($entry['type'] ?? ''));

            if ('' === $command || null === $type) {
                continue;
            }

            $row = [
                'command' => $command,
                'type'    => $type->value,
            ];

            // response/text accept either a flat string (legacy) or a
            // `lang => text` locale map produced by LocalizedTextarea.
            // Empty entries are dropped so the JSON column stays canonical.
            $response = $this->cleanLocalized($entry['response'] ?? null);
            if (null !== $response) {
                $row['response'] = $response;
            }

            if (CommandActionType::StartFlow === $type) {
                $flowId = $entry['flow_id'] ?? null;
                if (is_string($flowId) && '' !== $flowId) {
                    $row['flow_id'] = $flowId;
                }
            }

            if (CommandActionType::SendMessage === $type) {
                $text = $this->cleanLocalized($entry['text'] ?? null);
                if (null !== $text) {
                    $row['text'] = $text;
                }
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * Normalize a localized text field to the canonical shape stored in the
     * commands JSON column: either a non-empty string (legacy) or a locale
     * map with at least one non-empty entry. Returns null when nothing
     * meaningful was provided.
     *
     * @return string|array<string, string>|null
     */
    private function cleanLocalized(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return '' === mb_trim($value) ? null : $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $clean = [];
        foreach ($value as $lang => $text) {
            if (is_string($lang) && is_string($text) && '' !== mb_trim($text)) {
                $clean[$lang] = $text;
            }
        }

        return [] === $clean ? null : $clean;
    }

}
