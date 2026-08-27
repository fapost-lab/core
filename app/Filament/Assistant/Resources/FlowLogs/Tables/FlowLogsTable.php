<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowLogs\Tables;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class FlowLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('session'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('assistant.flow_logs.fields.created_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->since()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('assistant.flow_logs.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __('assistant.flow_logs.statuses.' . $state))
                    ->color(static fn (string $state): string => match ($state) {
                        'executed' => 'success',
                        'terminal' => 'success',
                        'waiting'  => 'warning',
                        'failed'   => 'danger',
                        'conflict' => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('node_type')
                    ->label(__('assistant.flow_logs.fields.node_type'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('node_id')
                    ->label(__('assistant.flow_logs.fields.node_id'))
                    ->fontFamily('mono')
                    ->extraCellAttributes(['class' => 'text-xs'])
                    ->limit(20),

                TextColumn::make('source_handle')
                    ->label(__('assistant.flow_logs.fields.source_handle'))
                    ->placeholder('—'),

                IconColumn::make('has_error')
                    ->label(__('assistant.flow_logs.fields.error'))
                    ->state(static fn ($record): bool => is_array($record->error ?? null) && [] !== $record->error)
                    ->boolean(),

                // Display the session_id truncated to 8 chars but copy the full UUID
                // on click. {@code copyableState()} provides the value sent to the
                // clipboard; {@code formatStateUsing()} only changes the rendered text.
                TextColumn::make('session_id')
                    ->label(__('assistant.flow_logs.fields.session_id'))
                    ->formatStateUsing(static fn (string $state): string => mb_substr($state, 0, 8))
                    ->tooltip(static fn ($record): ?string => is_object($record) ? (string) ($record->session_id ?? '') : null)
                    ->fontFamily('mono')
                    ->extraCellAttributes(['class' => 'text-xs'])
                    ->copyable()
                    ->copyableState(static fn ($record): string => (string) ($record->session_id ?? '')),
            ])
            ->filters([
                Filter::make('session_id')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('session_id')
                            ->label(__('assistant.flow_logs.filters.session_id'))
                            ->placeholder('UUID'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $value = is_string($data['session_id'] ?? null) ? mb_trim($data['session_id']) : '';

                        return '' === $value ? $query : $query->where('session_id', $value);
                    })
                    ->indicateUsing(static function (array $data): array {
                        $value = is_string($data['session_id'] ?? null) ? mb_trim($data['session_id']) : '';

                        return '' === $value ? [] : ['session=' . mb_substr($value, 0, 8)];
                    }),

                SelectFilter::make('node_type')
                    ->label(__('assistant.flow_logs.filters.node_type'))
                    ->options(self::nodeTypeOptions()),

                SelectFilter::make('status')
                    ->label(__('assistant.flow_logs.filters.status'))
                    ->options([
                        'executed' => __('assistant.flow_logs.statuses.executed'),
                        'waiting'  => __('assistant.flow_logs.statuses.waiting'),
                        'failed'   => __('assistant.flow_logs.statuses.failed'),
                        'terminal' => __('assistant.flow_logs.statuses.terminal'),
                    ]),

                Filter::make('has_error')
                    ->label(__('assistant.flow_logs.filters.has_error'))
                    ->query(static fn (Builder $q): Builder => $q->whereNotNull('error')),

                Filter::make('window')
                    ->schema([
                        DateTimePicker::make('from')->label(__('assistant.flow_logs.filters.from')),
                        DateTimePicker::make('to')->label(__('assistant.flow_logs.filters.to')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, static fn (Builder $q, string $from): Builder => $q->where('created_at', '>=', Carbon::parse($from)))
                        ->when($data['to'] ?? null, static fn (Builder $q, string $to): Builder => $q->where('created_at', '<=', Carbon::parse($to)))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([25, 50, 100, 250])
            ->poll('60s');
    }

    /**
     * Static list of V1 + legacy node types — covers everything currently in
     * the registry, kept here to avoid hitting the registry on every render.
     *
     * @return array<string, string>
     */
    private static function nodeTypeOptions(): array
    {
        return [
            'send_message' => 'send_message',
            'input'        => 'input',
            'branch'       => 'branch',
            'condition'    => 'condition',
            'delay'        => 'delay',
            'assign'       => 'assign',
            'call'         => 'call',
            'emit_event'   => 'emit_event',
            'rag_query'    => 'rag_query',
            'subflow'      => 'subflow',
            'end'          => 'end',
        ];
    }
}
