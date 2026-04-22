<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Pages;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Filament\Support\ContentLanguages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Assistant settings page — default language, default flow, fallback message and advanced settings JSON.
 */
final class AssistantSettings extends Page
{
    /** @var array<string, mixed>|null */
    public ?array $data            = [];
    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected string $view = 'filament.assistant.pages.assistant-settings';

    protected CurrentAssistantInterface $currentAssistant;
    protected TenantSettings $tenantSettings;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.settings');
    }

    public function getTitle(): string
    {
        return __('assistant.pages.settings.title');
    }

    public function boot(CurrentAssistantInterface $currentAssistant, TenantSettings $tenantSettings): void
    {
        $this->currentAssistant = $currentAssistant;
        $this->tenantSettings   = $tenantSettings;
    }

    public function mount(): void
    {
        $assistantData = $this->currentAssistant->get()->only([
            'default_language',
            'default_flow_id',
            'fallback_message',
            'settings',
        ]);

        $assistantData['available_languages'] = $this->tenantSettings->available_languages;

        $this->form->fill($assistantData);
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data               = $this->form->getState();
        $availableLanguages = $data['available_languages'] ?? [];
        unset($data['available_languages']);

        $this->currentAssistant->get()->update($data);

        $this->tenantSettings->available_languages = array_values(array_unique(array_filter(
            is_array($availableLanguages) ? $availableLanguages : [],
            static fn (mixed $language): bool => is_string($language) && '' !== $language,
        )));
        $this->tenantSettings->save();

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
                Section::make(__('assistant.pages.settings.sections.general'))
                    ->schema([
                        Select::make('default_language')
                            ->label(__('assistant.pages.settings.fields.default_language'))
                            ->options(ContentLanguages::options())
                            ->searchable()
                            ->nullable()
                            ->disabled($hasFlows)
                            ->hintIcon($hasFlows ? Heroicon::OutlinedLockClosed : null)
                            ->hintIconTooltip($hasFlows ? __('assistant.pages.settings.fields.default_language_locked') : null),
                        Select::make('available_languages')
                            ->label(__('assistant.pages.settings.fields.available_languages'))
                            ->options(ContentLanguages::options())
                            ->multiple()
                            ->searchable()
                            ->nullable(),
                        Select::make('default_flow_id')
                            ->label(__('assistant.pages.settings.fields.default_flow_id'))
                            ->options($flowOptions)
                            ->nullable()
                            ->searchable(),
                        Textarea::make('fallback_message')
                            ->label(__('assistant.pages.settings.fields.fallback_message'))
                            ->nullable()
                            ->rows(3),
                    ]),
                Section::make(__('assistant.pages.settings.sections.advanced'))
                    ->schema([
                        KeyValue::make('settings')
                            ->label(__('assistant.pages.settings.fields.settings'))
                            ->nullable(),
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

}
