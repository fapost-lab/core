<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Contacts\Tables;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Compact contact list for the assistant panel. Columns surface the operator's
 * routine triage signals: which channel the contact came from, their external
 * ID and human name (extracted from the {@code meta} JSON), language, and
 * recency. No row actions besides View — every mutation belongs to flows.
 */
final class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label(__('contact.fields.id'))
                    ->formatStateUsing(static fn (string $state): string => mb_substr($state, 0, 8))
                    ->tooltip(static fn ($record): ?string => is_object($record) && isset($record->id) ? (string) $record->id : null)
                    ->copyable()
                    ->fontFamily('mono')
                    ->extraCellAttributes(['class' => 'text-xs']),

                TextColumn::make('platform')
                    ->label(__('contact.fields.platform'))
                    ->badge()
                    ->formatStateUsing(static fn (PlatformEnum $state): string => ucfirst($state->value)),

                TextColumn::make('external_id')
                    ->label(__('contact.fields.external_id'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->limit(28),

                TextColumn::make('name')
                    ->label(__('contact.fields.name'))
                    ->state(static function ($record): ?string {
                        $meta = is_array($record->meta ?? null) ? $record->meta : [];

                        $first = (string) ($meta['first_name'] ?? '');
                        $last  = (string) ($meta['last_name'] ?? '');
                        $full  = mb_trim($first . ' ' . $last);

                        if ('' !== $full) {
                            return $full;
                        }

                        $username = (string) ($meta['username'] ?? '');

                        return '' !== $username ? '@' . $username : null;
                    })
                    ->placeholder('—')
                    ->limit(32),

                TextColumn::make('language')
                    ->label(__('contact.fields.language'))
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label(__('contact.fields.created_at'))
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->label(__('contact.filters.platform'))
                    ->options(self::platformOptions()),

                SelectFilter::make('language')
                    ->label(__('contact.filters.language'))
                    // Build the option list from a fresh Contact query —
                    // Filament's `options(Closure)` evaluator does not inject
                    // the table's underlying Builder here (signature varies
                    // by Filament version), so we go through the model
                    // directly to dodge a null-argument call to newQuery().
                    ->options(static fn (): array => Contact::query()
                        ->select('language')
                        ->whereNotNull('language')
                        ->distinct()
                        ->orderBy('language')
                        ->pluck('language', 'language')
                        ->toArray()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * @return array<string, string>
     */
    private static function platformOptions(): array
    {
        $options = [];
        foreach (PlatformEnum::cases() as $case) {
            $options[$case->value] = ucfirst($case->value);
        }

        return $options;
    }
}
