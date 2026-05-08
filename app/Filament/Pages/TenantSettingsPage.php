<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Filament\Support\ContentLanguages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Tenant-level configuration page (admin panel only).
 *
 * Surfaces every field that lives on {@see TenantSettings} so they can be
 * managed from one place rather than scattered across per-assistant forms.
 * Fields are grouped by concern: language matrix (base / available / fallback),
 * messaging limits, broadcast tuning, flow runtime defaults.
 *
 * The base content language is locked once any flow has been published —
 * changing it would orphan the existing language-keyed translations in flow
 * JSON. Locked indicator is shown via hint icon.
 */
final class TenantSettingsPage extends Page
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static ?string $slug = 'tenant-settings';

    protected static ?int $navigationSort = 100;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected string $view = 'filament.pages.tenant-settings';

    protected TenantSettings $tenantSettings;

    public static function getNavigationLabel(): string
    {
        return __('staff.tenant_settings.navigation');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return null;
    }

    public function getTitle(): string
    {
        return __('staff.tenant_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('staff.tenant_settings.subtitle');
    }

    public function boot(TenantSettings $tenantSettings): void
    {
        $this->tenantSettings = $tenantSettings;
    }

    public function mount(): void
    {
        $this->form->fill([
            'content_base_language'  => $this->tenantSettings->content_base_language,
            'available_languages'    => $this->tenantSettings->available_languages,
            'fallback_language'      => $this->tenantSettings->fallback_language,
            'messaging_rate_limit'   => $this->tenantSettings->messaging_rate_limit,
            'broadcast_chunk_size'   => $this->tenantSettings->broadcast_chunk_size,
            'broadcast_backpressure' => $this->tenantSettings->broadcast_backpressure,
            'flow_session_ttl'       => $this->tenantSettings->flow_session_ttl,
            'max_retry_attempts'     => $this->tenantSettings->max_retry_attempts,
            'flow_fallback_message'  => $this->tenantSettings->flow_fallback_message,
        ]);
    }

    public function save(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->form->getState();

        $available = $this->normalizeLanguageList($data['available_languages'] ?? []);
        $fallback  = is_string($data['fallback_language'] ?? null) ? $data['fallback_language'] : '';

        // Cross-field invariant: fallback must come from the available set.
        if ('' !== $fallback && [] !== $available && ! in_array($fallback, $available, true)) {
            Notification::make()
                ->danger()
                ->title(__('staff.tenant_settings.errors.fallback_not_in_available'))
                ->send();

            return;
        }

        // Lock base language once flows are published — see field hint.
        if ($this->isBaseLanguageLocked()
            && $data['content_base_language'] !== $this->tenantSettings->content_base_language
        ) {
            Notification::make()
                ->danger()
                ->title(__('staff.tenant_settings.errors.base_lang_locked'))
                ->send();

            return;
        }

        $this->tenantSettings->content_base_language  = (string) $data['content_base_language'];
        $this->tenantSettings->available_languages    = $available;
        $this->tenantSettings->fallback_language      = $fallback;
        $this->tenantSettings->messaging_rate_limit   = (int) $data['messaging_rate_limit'];
        $this->tenantSettings->broadcast_chunk_size   = (int) $data['broadcast_chunk_size'];
        $this->tenantSettings->broadcast_backpressure = (bool) $data['broadcast_backpressure'];
        $this->tenantSettings->flow_session_ttl       = (int) $data['flow_session_ttl'];
        $this->tenantSettings->max_retry_attempts     = (int) $data['max_retry_attempts'];
        $this->tenantSettings->flow_fallback_message  = (string) $data['flow_fallback_message'];
        $this->tenantSettings->save();

        Notification::make()
            ->success()
            ->title(__('staff.tenant_settings.saved'))
            ->send();
    }

    public function form(Schema $schema): Schema
    {
        $baseLocked = $this->isBaseLanguageLocked();

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('staff.tenant_settings.tabs.languages'))
                            ->icon(Heroicon::OutlinedLanguage)
                            ->columns(3)
                            ->schema([
                                Select::make('content_base_language')
                                    ->label(__('staff.tenant_settings.fields.content_base_language'))
                                    ->helperText(__('staff.tenant_settings.fields.content_base_language_help'))
                                    ->options(ContentLanguages::options())
                                    ->searchable()
                                    ->required()
                                    ->disabled($baseLocked)
                                    ->dehydrated(true)
                                    ->hintIcon($baseLocked ? Heroicon::OutlinedLockClosed : null)
                                    ->hintIconTooltip($baseLocked ? __('staff.tenant_settings.errors.base_lang_locked') : null),

                                Select::make('available_languages')
                                    ->label(__('staff.tenant_settings.fields.available_languages'))
                                    ->helperText(__('staff.tenant_settings.fields.available_languages_help'))
                                    ->options(ContentLanguages::options())
                                    ->multiple()
                                    ->searchable()
                                    ->required()
                                    ->minItems(1),

                                Select::make('fallback_language')
                                    ->label(__('staff.tenant_settings.fields.fallback_language'))
                                    ->helperText(__('staff.tenant_settings.fields.fallback_language_help'))
                                    ->options(static function (Get $get): array {
                                        $available = $get('available_languages');
                                        $available = is_array($available) ? array_values(array_filter(
                                            $available,
                                            static fn (mixed $v): bool => is_string($v) && '' !== $v,
                                        )) : [];

                                        if ([] === $available) {
                                            return ContentLanguages::options();
                                        }

                                        $all = ContentLanguages::options();

                                        return array_intersect_key($all, array_flip($available));
                                    })
                                    ->searchable()
                                    ->required(),
                            ]),

                        Tab::make(__('staff.tenant_settings.tabs.runtime'))
                            ->icon(Heroicon::OutlinedArrowsRightLeft)
                            ->schema([
                                Section::make(__('staff.tenant_settings.sections.messaging'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('messaging_rate_limit')
                                            ->label(__('staff.tenant_settings.fields.messaging_rate_limit'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->required(),
                                    ]),
                                Section::make(__('staff.tenant_settings.sections.flow'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('flow_session_ttl')
                                            ->label(__('staff.tenant_settings.fields.flow_session_ttl'))
                                            ->numeric()
                                            ->minValue(60)
                                            ->required(),
                                        TextInput::make('max_retry_attempts')
                                            ->label(__('staff.tenant_settings.fields.max_retry_attempts'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        Textarea::make('flow_fallback_message')
                                            ->label(__('staff.tenant_settings.fields.flow_fallback_message'))
                                            ->columnSpanFull()
                                            ->rows(2),
                                    ]),
                            ]),

                        Tab::make(__('staff.tenant_settings.tabs.broadcasts'))
                            ->icon(Heroicon::OutlinedMegaphone)
                            ->columns(2)
                            ->schema([
                                TextInput::make('broadcast_chunk_size')
                                    ->label(__('staff.tenant_settings.fields.broadcast_chunk_size'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->required(),
                                Toggle::make('broadcast_backpressure')
                                    ->label(__('staff.tenant_settings.fields.broadcast_backpressure')),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('staff.tenant_settings.actions.save'))
                ->action('save'),
        ];
    }

    private function isBaseLanguageLocked(): bool
    {
        return FlowDefinition::query()->exists();
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeLanguageList(mixed $raw): array
    {
        if ( ! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $raw,
            static fn (mixed $code): bool => is_string($code) && '' !== $code,
        )));
    }
}
