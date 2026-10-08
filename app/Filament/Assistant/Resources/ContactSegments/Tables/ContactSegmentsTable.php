<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments\Tables;

use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Services\ContactSegmentResolver;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ContactSegmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('segment.fields.name'))
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('conditions_count')
                    ->label(__('segment.fields.conditions'))
                    ->state(static fn (ContactSegment $record): int => is_array($record->rules['conditions'] ?? null)
                        ? count($record->rules['conditions'])
                        : 0)
                    ->badge()
                    ->alignCenter(),

                TextColumn::make('cached_count')
                    ->label(__('segment.fields.size'))
                    ->placeholder('—')
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('cached_count_at')
                    ->label(__('segment.fields.counted_at'))
                    ->dateTime()
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('refresh_count')
                    ->label(__('segment.actions.refresh_count'))
                    ->icon(Heroicon::ArrowPath)
                    ->authorize('update')
                    ->action(static function (ContactSegment $record): void {
                        $count = app(ContactSegmentResolver::class)->refreshCount($record);

                        Notification::make()
                            ->success()
                            ->title(__('segment.notifications.counted', ['count' => $count]))
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
