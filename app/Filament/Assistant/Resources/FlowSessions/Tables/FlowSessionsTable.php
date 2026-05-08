<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowSessions\Tables;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Live + historical FlowSession table. Default ordering surfaces the most
 * recently updated session first (most useful for live triage). Filters
 * collapse a 30-day operational window; for full postmortem history reach
 * the same table from the admin panel (V1.x — global FlowSession resource).
 */
final class FlowSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with(['contact', 'flowDefinition']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label(__('assistant.flow_sessions.fields.id'))
                    ->formatStateUsing(static fn (string $state): string => mb_substr($state, 0, 8))
                    ->tooltip(static fn ($record): ?string => is_object($record) && isset($record->id) ? (string) $record->id : null)
                    ->copyable()
                    ->fontFamily('mono')
                    ->extraCellAttributes(['class' => 'text-xs']),

                TextColumn::make('status')
                    ->label(__('assistant.flow_sessions.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (FlowSessionStatus $state): string => __('assistant.flow_sessions.statuses.' . $state->value))
                    ->color(static fn (FlowSessionStatus $state): string => match ($state) {
                        FlowSessionStatus::Active       => 'info',
                        FlowSessionStatus::WaitingInput => 'warning',
                        FlowSessionStatus::Paused,
                        FlowSessionStatus::PausedSubflow => 'gray',
                        FlowSessionStatus::Completed,
                        FlowSessionStatus::Ended => 'success',
                        FlowSessionStatus::Failed,
                        FlowSessionStatus::Expired => 'danger',
                        FlowSessionStatus::Cancelled,
                        FlowSessionStatus::TerminatedByUser => 'gray',
                        default                             => 'gray',
                    }),

                TextColumn::make('end_status')
                    ->label(__('assistant.flow_sessions.fields.end_status'))
                    ->badge()
                    ->placeholder('—')
                    ->color(static fn (?string $state): string => match ($state) {
                        'success'   => 'success',
                        'cancelled' => 'gray',
                        'failed'    => 'danger',
                        default     => 'gray',
                    }),

                TextColumn::make('contact.external_id')
                    ->label(__('assistant.flow_sessions.fields.contact'))
                    ->limit(24)
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        return $query->whereHas('contact', static function (Builder $q) use ($search): void {
                            $q->where('external_id', 'ilike', "%{$search}%")
                                ->orWhere('id', $search);
                        });
                    }),

                TextColumn::make('flowDefinition.name')
                    ->label(__('assistant.flow_sessions.fields.flow'))
                    ->placeholder('—')
                    ->limit(28),

                TextColumn::make('current_node_id')
                    ->label(__('assistant.flow_sessions.fields.current_node_id'))
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->extraCellAttributes(['class' => 'text-xs']),

                TextColumn::make('updated_at')
                    ->label(__('assistant.flow_sessions.fields.updated_at'))
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('live_only')
                    ->label(__('assistant.flow_sessions.filters.live_only'))
                    ->placeholder('—')
                    ->trueLabel(__('assistant.flow_sessions.filters.live_only'))
                    ->falseLabel('—')
                    ->queries(
                        true: static fn (Builder $q): Builder => $q->whereIn('status', [
                            FlowSessionStatus::Active->value,
                            FlowSessionStatus::WaitingInput->value,
                            FlowSessionStatus::PausedSubflow->value,
                        ]),
                        false: static fn (Builder $q): Builder => $q,
                        blank: static fn (Builder $q): Builder => $q,
                    )
                    ->default(true),

                SelectFilter::make('status')
                    ->label(__('assistant.flow_sessions.filters.status'))
                    ->options(self::statusOptions()),

                SelectFilter::make('flow_definition_id')
                    ->label(__('assistant.flow_sessions.filters.flow'))
                    ->options(static fn () => FlowDefinition::query()
                        ->orderBy('name')
                        ->limit(200)
                        ->pluck('name', 'id')
                        ->toArray()),

                Filter::make('created_window')
                    ->schema([
                        \Filament\Forms\Components\DateTimePicker::make('from')
                            ->label(__('assistant.flow_sessions.filters.created_from')),
                        \Filament\Forms\Components\DateTimePicker::make('to')
                            ->label(__('assistant.flow_sessions.filters.created_to')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            static fn (Builder $q, string $from): Builder => $q->where('created_at', '>=', Carbon::parse($from)),
                        )
                        ->when(
                            $data['to'] ?? null,
                            static fn (Builder $q, string $to): Builder => $q->where('created_at', '<=', Carbon::parse($to)),
                        )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([25, 50, 100])
            ->poll('30s');
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (FlowSessionStatus::cases() as $case) {
            $options[$case->value] = __('assistant.flow_sessions.statuses.' . $case->value);
        }

        return $options;
    }
}
