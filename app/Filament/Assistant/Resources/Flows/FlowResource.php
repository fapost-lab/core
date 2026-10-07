<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Flows;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Flows\Pages\CreateFlow;
use App\Filament\Assistant\Resources\Flows\Pages\EditFlow;
use App\Filament\Assistant\Resources\Flows\Pages\ListFlows;
use App\Filament\Assistant\Resources\Flows\Schemas\FlowFormSchema;
use App\Filament\Assistant\Resources\Flows\Tables\FlowsTable;
use App\Filament\Support\RecordLimit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Closed for everyone, admins included, once the tenant is at its limit.
     * The policy cannot do this: admins pass `Gate::before`.
     */
    public static function canCreate(): bool
    {
        return static::canCreateIgnoringLimit() && ! self::isLimitReached();
    }

    /**
     * The policy answer alone: a create page checks it on every request, the limit only on mount.
     */
    public static function canCreateIgnoringLimit(): bool
    {
        return parent::canCreate();
    }

    /**
     * The tenant's limit as it stands now: whether it is reached and the "N of M" hint.
     */
    public static function limit(): RecordLimit
    {
        return new RecordLimit(FlowDraft::LIMIT_KEY, FlowDraft::countForLimit(), 'assistant.flows.limit');
    }

    public static function isLimitReached(): bool
    {
        return self::limit()->reached;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('trigger')
            ->addSelect([
                'published_version' => FlowDefinition::query()
                    ->selectRaw('MAX(version)')
                    ->whereColumn('flow_id', 'flow_drafts.flow_id')
                    ->where('is_active', true),
            ]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('viewAny', FlowDraft::class);
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
