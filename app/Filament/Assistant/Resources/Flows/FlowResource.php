<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Flows;

use App\Domains\Flow\Models\FlowDraft;
use App\Filament\Assistant\Resources\Flows\Pages\CreateFlow;
use App\Filament\Assistant\Resources\Flows\Pages\EditFlow;
use App\Filament\Assistant\Resources\Flows\Pages\ListFlows;
use App\Filament\Assistant\Resources\Flows\Schemas\FlowFormSchema;
use App\Filament\Assistant\Resources\Flows\Tables\FlowsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class FlowResource extends Resource
{
    protected static ?string $model = FlowDraft::class;

    protected static ?string $tenantOwnershipRelationshipName = 'assistant';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.flow');
    }

    public static function getModelLabel(): string
    {
        return __('assistant.flows.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assistant.flows.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return FlowFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FlowsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListFlows::route('/'),
            'create' => CreateFlow::route('/create'),
            'edit'   => EditFlow::route('/{record}/edit'),
        ];
    }
}
