<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowSessions;

use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\FlowSessions\Pages\ListFlowSessions;
use App\Filament\Assistant\Resources\FlowSessions\Pages\ViewFlowSession;
use App\Filament\Assistant\Resources\FlowSessions\Schemas\FlowSessionInfolistSchema;
use App\Filament\Assistant\Resources\FlowSessions\Tables\FlowSessionsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Read-only inspector for {@see FlowSession} rows scoped to the current
 * assistant. Operations team uses it to triage stuck sessions, inspect
 * persisted state, follow subflow chains, and find correlated logs.
 *
 * No CRUD pages — sessions are owned by the engine, never edited by hand.
 * The list filters live (Active / WaitingInput / PausedSubflow) by default;
 * a TernaryFilter exposes terminal sessions for postmortem.
 */
final class FlowSessionResource extends Resource
{
    protected static ?string $model = FlowSession::class;

    protected static ?string $tenantOwnershipRelationshipName = 'assistant';

    protected static ?int $navigationSort = 60;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('assistant.flow_sessions.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assistant.flow_sessions.plural_label');
    }

    public static function table(Table $table): Table
    {
        return FlowSessionsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FlowSessionInfolistSchema::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlowSessions::route('/'),
            'view'  => ViewFlowSession::route('/{record}'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('viewAny', FlowSession::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
