<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts\Tables;

use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastDispatcher;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Broadcast list with lifecycle-aware row actions: Send (draft only, confirmable),
 * Cancel (running only), Edit/Delete (draft only).
 */
final class BroadcastsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('broadcast.fields.name'))
                    ->weight('medium')
                    ->searchable()
                    ->limit(40),

                TextColumn::make('target_type')
                    ->label(__('broadcast.fields.target'))
                    ->badge()
                    ->formatStateUsing(static fn (BroadcastTarget $state): string => __('broadcast.targets.' . $state->value)),

                TextColumn::make('status')
                    ->label(__('broadcast.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (BroadcastStatus $state): string => __('broadcast.statuses.' . $state->value))
                    ->color(static fn (BroadcastStatus $state): string => match ($state) {
                        BroadcastStatus::Draft     => 'gray',
                        BroadcastStatus::Running   => 'info',
                        BroadcastStatus::Completed => 'success',
                        BroadcastStatus::Failed    => 'danger',
                        BroadcastStatus::Cancelled => 'warning',
                    }),

                TextColumn::make('progress')
                    ->label(__('broadcast.fields.progress'))
                    ->state(static fn (Broadcast $record): string => "{$record->sent_count}/{$record->total_recipients}")
                    ->alignEnd(),

                TextColumn::make('failed_count')
                    ->label(__('broadcast.fields.failed'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('broadcast.fields.created_at'))
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('broadcast.fields.status'))
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                Action::make('send')
                    ->label(__('broadcast.actions.send'))
                    ->icon(Heroicon::PaperAirplane)
                    ->color('success')
                    ->authorize('send')
                    ->visible(static fn (Broadcast $record): bool => BroadcastStatus::Draft === $record->status)
                    ->requiresConfirmation()
                    ->modalHeading(__('broadcast.actions.send_confirm_title'))
                    ->modalDescription(__('broadcast.actions.send_confirm_body'))
                    ->action(static function (Broadcast $record): void {
                        $started = app(BroadcastDispatcher::class)->start($record);

                        Notification::make()
                            ->status($started ? 'success' : 'warning')
                            ->title($started ? __('broadcast.notifications.started') : __('broadcast.notifications.already_started'))
                            ->send();
                    }),

                Action::make('cancel')
                    ->label(__('broadcast.actions.cancel'))
                    ->icon(Heroicon::XCircle)
                    ->color('warning')
                    ->authorize('cancel')
                    ->visible(static fn (Broadcast $record): bool => BroadcastStatus::Running === $record->status)
                    ->requiresConfirmation()
                    ->action(static function (Broadcast $record): void {
                        Broadcast::query()
                            ->whereKey($record->getKey())
                            ->where('status', BroadcastStatus::Running->value)
                            ->update([
                                'status'       => BroadcastStatus::Cancelled->value,
                                'completed_at' => Carbon::now(),
                            ]);

                        Notification::make()->success()->title(__('broadcast.notifications.cancelled'))->send();
                    }),

                EditAction::make()
                    ->visible(static fn (Broadcast $record): bool => $record->status->isEditable()),

                DeleteAction::make()
                    ->visible(static fn (Broadcast $record): bool => BroadcastStatus::Running !== $record->status),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (BroadcastStatus::cases() as $case) {
            $options[$case->value] = __('broadcast.statuses.' . $case->value);
        }

        return $options;
    }
}
