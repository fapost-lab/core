<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowGroups;

use App\Domains\Flow\Models\FlowGroup;
use App\Filament\Assistant\Resources\FlowGroups\Pages\CreateFlowGroup;
use App\Filament\Assistant\Resources\FlowGroups\Pages\EditFlowGroup;
use App\Filament\Assistant\Resources\FlowGroups\Pages\ListFlowGroups;
use App\Filament\Assistant\Resources\FlowGroups\Schemas\FlowGroupFormSchema;
use App\Filament\Assistant\Resources\FlowGroups\Tables\FlowGroupsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class FlowGroupResource extends Resource
{
    protected static ?string $model = FlowGroup::class;

    protected static ?string $tenantOwnershipRelationshipName = 'assistant';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.flow');
    }

    public static function getModelLabel(): string
    {
        return __('assistant.flow_groups.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assistant.flow_groups.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return FlowGroupFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FlowGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListFlowGroups::route('/'),
            'create' => CreateFlowGroup::route('/create'),
            'edit'   => EditFlowGroup::route('/{record}/edit'),
        ];
    }
}
