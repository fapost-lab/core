<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowLogs;

use App\Domains\Flow\Models\FlowLog;
use App\Filament\Assistant\Resources\FlowLogs\Pages\ListFlowLogs;
use App\Filament\Assistant\Resources\FlowLogs\Pages\ViewFlowLog;
use App\Filament\Assistant\Resources\FlowLogs\Tables\FlowLogsTable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only viewer over the partitioned `flow_logs` table — one row per node
 * execution. Filters cover the typical debugging queries (per session,
 * per node type, errors-only, time window). Default order is newest-first;
 * polling refresh keeps live executions visible.
 *
 * Tenant scoping: flow_logs has no direct assistant_id; we filter via the
 * parent session row using {@code whereHas('session', …)}. The table query
 * eager-loads the session for column display.
 */
final class FlowLogResource extends Resource
{
    protected static ?string $model = FlowLog::class;

    /**
     * flow_logs has no direct relationship to {@see \App\Domains\Assistant\Models\Assistant};
     * tenant scoping is applied manually in {@see getEloquentQuery()} via the
     * parent session row. Disable the framework's automatic scope to avoid
     * requiring a fake ownership relationship on the model.
     */
    protected static bool $isScopedToTenant = false;

    protected static ?int $navigationSort = 70;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('assistant.flow_logs.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assistant.flow_logs.plural_label');
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return parent::getEloquentQuery()
            ->when(
                null !== $tenant,
                static fn (Builder $q): Builder => $q->whereHas(
                    'session',
                    static fn (Builder $sub): Builder => $sub->where('assistant_id', $tenant->getKey()),
                ),
            );
    }

    public static function table(Table $table): Table
    {
        return FlowLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('assistant.flow_logs.label'))
                ->columns(3)
                ->components([
                    \Filament\Infolists\Components\TextEntry::make('id')->copyable()->fontFamily('mono'),
                    \Filament\Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    \Filament\Infolists\Components\TextEntry::make('status')->badge(),
                    \Filament\Infolists\Components\TextEntry::make('session_id')
                        ->label(__('assistant.flow_logs.fields.session_id'))
                        ->copyable()
                        ->fontFamily('mono'),
                    \Filament\Infolists\Components\TextEntry::make('node_id')
                        ->label(__('assistant.flow_logs.fields.node_id'))
                        ->fontFamily('mono'),
                    \Filament\Infolists\Components\TextEntry::make('node_type')
                        ->label(__('assistant.flow_logs.fields.node_type')),
                    \Filament\Infolists\Components\TextEntry::make('node_version')
                        ->label(__('assistant.flow_logs.fields.node_version')),
                    \Filament\Infolists\Components\TextEntry::make('source_handle')
                        ->label(__('assistant.flow_logs.fields.source_handle'))
                        ->placeholder('—'),
                ]),

            Section::make(__('assistant.flow_logs.fields.state_changes'))
                ->collapsed(true)
                ->visible(static fn ($record): bool => is_array($record->state_changes ?? null) && [] !== $record->state_changes)
                ->components([
                    \Filament\Infolists\Components\KeyValueEntry::make('state_changes')
                        ->state(static fn ($record): array => self::stringifyMap(is_array($record->state_changes ?? null) ? $record->state_changes : [])),
                ]),

            Section::make(__('assistant.flow_logs.fields.resolved'))
                ->collapsed(true)
                ->visible(static fn ($record): bool => is_array($record->resolved ?? null) && [] !== $record->resolved)
                ->components([
                    \Filament\Infolists\Components\KeyValueEntry::make('resolved')
                        ->state(static fn ($record): array => self::stringifyMap(is_array($record->resolved ?? null) ? $record->resolved : [])),
                ]),

            Section::make(__('assistant.flow_logs.fields.error'))
                ->visible(static fn ($record): bool => is_array($record->error ?? null) && [] !== $record->error)
                ->components([
                    \Filament\Infolists\Components\KeyValueEntry::make('error')
                        ->state(static fn ($record): array => self::stringifyMap(is_array($record->error ?? null) ? $record->error : [])),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlowLogs::route('/'),
            'view'  => ViewFlowLog::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, string>
     */
    private static function stringifyMap(array $map): array
    {
        $out = [];

        foreach ($map as $key => $value) {
            $out[(string) $key] = is_string($value)
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $out;
    }
}
