<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;
use App\Domains\Flow\Contracts\TranslationOverrideRepositoryInterface;
use App\Domains\Flow\Contracts\TranslationOverrideServiceInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Shared admin UI for translation overrides — one row per system catalog
 * key, one column per platform language. Cell payload describes whether
 * the value is an override, an inherited value (only relevant for the
 * assistant scope, where tenant overrides also count) or the catalog default.
 *
 * Concrete subclasses are thin wrappers that decide:
 *  - which scope id to write under (tenant_id / assistant_id),
 *  - which override repository to read,
 *  - which service to call on save/reset,
 *  - whether to overlay the tenant layer when computing cell status.
 */
abstract class AbstractTranslationsPage extends Page implements HasActions, HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected string $view = 'filament.pages.translations';

    abstract protected function scopeId(): string;

    abstract protected function repository(): TranslationOverrideRepositoryInterface;

    abstract protected function service(): TranslationOverrideServiceInterface;

    /**
     * Ordered list of override layers consulted top-down. Each layer is a
     * pair `[matrix, status]` where `matrix` is `key → lang → value` and
     * `status` is the badge tag rendered for cells satisfied by that layer.
     *
     * Tenant page returns one layer (its own override, status = "override").
     * Assistant page returns two: assistant override ("override") then
     * tenant override ("inherited"). The first non-empty hit wins.
     *
     * @return list<array{matrix: array<string, array<string, string>>, status: string}>
     */
    abstract protected function layers(): array;

    public function getTitle(): string
    {
        return __('staff.tenant_translations.plural_label');
    }

    public function getSubheading(): ?string
    {
        return __('staff.tenant_translations.subtitle');
    }

    public function table(Table $table): Table
    {
        $catalog          = app(SystemTranslationCatalogInterface::class);
        $availableLocales = $this->locales();

        $columns = [
            TextColumn::make('group')
                ->label(__('staff.tenant_translations.fields.group'))
                ->badge()
                ->color('gray')
                ->sortable(),
            TextColumn::make('key')
                ->label(__('staff.tenant_translations.fields.key'))
                ->fontFamily('mono')
                ->extraCellAttributes(['class' => 'text-xs'])
                ->searchable()
                ->sortable(),
            TextColumn::make('description')
                ->label(__('staff.tenant_translations.fields.description'))
                ->wrap()
                ->extraCellAttributes(['class' => 'text-xs text-gray-500']),
        ];

        // One column per locale. Cell payload is precomputed by buildRows()
        // into {value, status} where status ∈ override|inherited|default.
        foreach ($availableLocales as $locale) {
            $columns[] = TextColumn::make("locale_{$locale}")
                ->label($locale)
                ->state(static fn (array $record): string => $record['locales'][$locale]['value'] ?? '—')
                ->wrap()
                ->limit(60)
                ->badge(static fn (array $record): bool => 'default' !== ($record['locales'][$locale]['status'] ?? 'default'))
                ->color(static fn (array $record): string => match ($record['locales'][$locale]['status'] ?? 'default') {
                    'override'  => 'warning',
                    'inherited' => 'info',
                    default     => 'gray',
                });
        }

        $groupOptions = [];
        foreach ($catalog->entries() as $entry) {
            $groupOptions[$entry->group] = $entry->group;
        }

        return $table
            ->records(fn (): array => $this->buildRows())
            ->columns($columns)
            ->filters([
                SelectFilter::make('group')
                    ->label(__('staff.tenant_translations.fields.group'))
                    ->options($groupOptions),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('staff.tenant_translations.actions.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(static fn (array $arguments): string => $arguments['key'] ?? '')
                    ->modalSubmitActionLabel(__('staff.tenant_translations.actions.save'))
                    ->fillForm(fn (array $arguments): array => $this->editFormDefaults((string) ($arguments['key'] ?? '')))
                    ->schema(fn (array $arguments): array => $this->editFormSchema((string) ($arguments['key'] ?? '')))
                    ->action(fn (array $data, array $arguments) => $this->saveOverrides((string) ($arguments['key'] ?? ''), $data)),
                Action::make('reset')
                    ->label(__('staff.tenant_translations.actions.reset'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(static fn (array $record): bool => self::hasAnyOverride($record))
                    ->action(fn (array $arguments) => $this->resetKey((string) ($arguments['key'] ?? ''))),
            ])
            ->paginated([25, 50, 100]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    /**
     * Stable list of locales rendered as columns. Always includes `en` as
     * baseline because the catalog uses it as the last fallback before
     * returning the bare key.
     *
     * @return list<string>
     */
    protected function locales(): array
    {
        $configured = app(TenantSettings::class)->available_languages;

        if (!in_array('en', $configured, true)) {
            $configured = array_merge(['en'], $configured);
        }

        /** @var list<string> $list */
        $list = array_values(array_unique($configured));

        return $list;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function hasAnyOverride(array $record): bool
    {
        $locales = is_array($record['locales'] ?? null) ? $record['locales'] : [];

        foreach ($locales as $payload) {
            if (is_array($payload) && 'override' === ($payload['status'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{key: string, group: string, description: string, locales: array<string, array{value: string, status: string}>}>
     */
    private function buildRows(): array
    {
        $catalog = app(SystemTranslationCatalogInterface::class);
        $locales = $this->locales();
        $layers  = $this->layers();

        $rows = [];
        foreach ($catalog->entries() as $entry) {
            $localesPayload = [];
            foreach ($locales as $lang) {
                $default               = $entry->default($lang) ?? ($entry->default('en') ?? '');
                $localesPayload[$lang] = $this->resolveCell($entry->key, $lang, $default, $layers);
            }

            $rows[] = [
                'key'         => $entry->key,
                'group'       => $entry->group,
                'description' => $entry->getDescription(app()->getLocale()),
                'locales'     => $localesPayload,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{matrix: array<string, array<string, string>>, status: string}>  $layers
     * @return array{value: string, status: string}
     */
    private function resolveCell(string $key, string $language, string $catalogDefault, array $layers): array
    {
        foreach ($layers as $layer) {
            $value = $layer['matrix'][$key][$language] ?? null;

            if (null !== $value) {
                return ['value' => $value, 'status' => $layer['status']];
            }
        }

        return ['value' => $catalogDefault, 'status' => 'default'];
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function editFormSchema(string $key): array
    {
        $catalog = app(SystemTranslationCatalogInterface::class);
        $entry   = $catalog->find($key);

        $components = [];
        foreach ($this->locales() as $lang) {
            $default = null !== $entry ? ($entry->default($lang) ?? $entry->default('en')) : null;

            $components[] = Textarea::make("values.{$lang}")
                ->label(mb_strtoupper($lang))
                ->placeholder($default ?? '')
                ->helperText(null !== $default
                    ? __('staff.tenant_translations.fields.locale_default_hint', ['default' => $default])
                    : null)
                ->rows(3);
        }

        return [
            Section::make($entry?->description ?? $key)
                ->schema($components)
                ->columns(1),
        ];
    }

    /**
     * @return array{values: array<string, string>}
     */
    private function editFormDefaults(string $key): array
    {
        $matrix = $this->repository()->matrix($this->scopeId());

        $values = [];
        foreach ($this->locales() as $lang) {
            $values[$lang] = $matrix[$key][$lang] ?? '';
        }

        return ['values' => $values];
    }

    /**
     * @param  array{values?: array<string, string>}  $data
     */
    private function saveOverrides(string $key, array $data): void
    {
        $service = $this->service();
        $scope   = $this->scopeId();
        $values  = is_array($data['values'] ?? null) ? $data['values'] : [];

        foreach ($this->locales() as $lang) {
            $value = is_string($values[$lang] ?? null) ? mb_trim($values[$lang]) : '';

            if ('' === $value) {
                $service->delete($scope, $key, $lang);
            } else {
                $service->upsert($scope, $key, $lang, $value);
            }
        }

        Notification::make()
            ->success()
            ->title(__('staff.tenant_translations.saved'))
            ->send();
    }

    private function resetKey(string $key): void
    {
        $service = $this->service();
        $scope   = $this->scopeId();

        foreach ($this->locales() as $lang) {
            $service->delete($scope, $key, $lang);
        }

        Notification::make()
            ->success()
            ->title(__('staff.tenant_translations.reset_done'))
            ->send();
    }
}
