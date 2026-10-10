<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;
use App\Domains\Flow\Services\TranslationOverrideEditor;
use App\Domains\Flow\Translations\TranslationScope;
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
 * Concrete subclasses only name the layer they edit ({@see TranslationScope}); the rows, the layering and the
 * writes are {@see TranslationOverrideEditor}'s, shared with the Inertia console's translations screens.
 */
abstract class AbstractTranslationsPage extends Page implements HasActions, HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected string $view = 'filament.pages.translations';

    abstract protected function scope(): TranslationScope;

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
                    ->modalHeading(static fn (array $record): string => (string) ($record['key'] ?? ''))
                    ->modalSubmitActionLabel(__('staff.tenant_translations.actions.save'))
                    ->fillForm(fn (array $record): array => $this->editFormDefaults((string) ($record['key'] ?? '')))
                    ->schema(fn (array $record): array => $this->editFormSchema((string) ($record['key'] ?? '')))
                    ->action(fn (array $data, array $record) => $this->saveOverrides((string) ($record['key'] ?? ''), $data)),
                Action::make('reset')
                    ->label(__('staff.tenant_translations.actions.reset'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(static fn (array $record): bool => self::hasAnyOverride($record))
                    ->action(fn (array $record) => $this->resetKey((string) ($record['key'] ?? ''))),
            ])
            ->paginated([25, 50, 100]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    /**
     * The locales rendered as columns, `en` always among them.
     *
     * @return list<string>
     */
    protected function locales(): array
    {
        return $this->editor()->languages();
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
     * @return array<string, array{key: string, group: string, description: string, locales: array<string, array{value: string, status: string}>}>
     */
    private function buildRows(): array
    {
        $rows = [];

        foreach ($this->editor()->rows($this->scope(), app()->getLocale()) as $row) {
            $locales = [];

            foreach ($row['languages'] as $cell) {
                $locales[$cell['language']] = ['value' => $cell['value'], 'status' => $cell['status']];
            }

            $rows[$row['key']] = [
                'key'         => $row['key'],
                'group'       => $row['group'],
                'description' => $row['description'],
                'locales'     => $locales,
            ];
        }

        return $rows;
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
            Section::make($entry?->getDescription(app()->getLocale()) ?? $key)
                ->schema($components)
                ->columns(1),
        ];
    }

    /**
     * @return array{values: array<string, string>}
     */
    private function editFormDefaults(string $key): array
    {
        return ['values' => $this->editor()->overrides($this->scope(), $key)];
    }

    /**
     * @param  array{values?: array<string, string>}  $data
     */
    private function saveOverrides(string $key, array $data): void
    {
        $values = is_array($data['values'] ?? null) ? $data['values'] : [];

        $this->editor()->save($this->scope(), $key, $values);

        Notification::make()
            ->success()
            ->title(__('staff.tenant_translations.saved'))
            ->send();
    }

    private function resetKey(string $key): void
    {
        $this->editor()->reset($this->scope(), $key);

        Notification::make()
            ->success()
            ->title(__('staff.tenant_translations.reset_done'))
            ->send();
    }

    private function editor(): TranslationOverrideEditor
    {
        return app(TranslationOverrideEditor::class);
    }
}
