<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts;

use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Broadcasts\Pages\CreateBroadcast;
use App\Filament\Assistant\Resources\Broadcasts\Pages\EditBroadcast;
use App\Filament\Assistant\Resources\Broadcasts\Pages\ListBroadcasts;
use App\Filament\Assistant\Resources\Broadcasts\Schemas\BroadcastFormSchema;
use App\Filament\Assistant\Resources\Broadcasts\Tables\BroadcastsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Broadcast composer / manager (assistant panel). Draft broadcasts are editable;
 * once a run starts the record is read-only and lifecycle is driven by the
 * fan-out jobs. Sending is a confirmable row action (see {@see BroadcastsTable}).
 */
final class BroadcastResource extends Resource
{
    protected static ?string $model = Broadcast::class;

    protected static ?string $tenantOwnershipRelationshipName = 'assistant';

    protected static ?int $navigationSort = 80;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('broadcast.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('broadcast.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return BroadcastFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BroadcastsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBroadcasts::route('/'),
            'create' => CreateBroadcast::route('/create'),
            'edit'   => EditBroadcast::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user() instanceof User;
    }

    /**
     * A broadcast is only editable while still a draft — a started/finished run is
     * an immutable record.
     */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof Broadcast && $record->status->isEditable();
    }
}
