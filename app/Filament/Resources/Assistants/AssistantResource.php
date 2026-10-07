<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Models\User;
use App\Filament\Resources\Assistants\Pages\CreateAssistant;
use App\Filament\Resources\Assistants\Pages\EditAssistant;
use App\Filament\Resources\Assistants\Pages\ListAssistants;
use App\Filament\Resources\Assistants\Pages\ViewAssistant;
use App\Filament\Resources\Assistants\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\Assistants\Schemas\AssistantFormSchema;
use App\Filament\Resources\Assistants\Tables\AssistantsTable;
use App\Filament\Support\RecordLimit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Admin panel only: CRUD for {@see Assistant} records and related admin relation managers.
 * The operational assistant console is {@see \App\Providers\Filament\AssistantPanelProvider} (dashboard + channels);
 * do not duplicate assistant CRUD there.
 */
final class AssistantResource extends Resource
{
    protected static ?string $model = Assistant::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::RocketLaunch;

    public static function getNavigationGroup(): ?string
    {
        return __('staff.assistants.label');
    }

    public static function getModelLabel(): string
    {
        return __('staff.assistants.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('staff.assistants.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return AssistantFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssistantsTable::configure($table);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            ChannelsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAssistants::route('/'),
            'create' => CreateAssistant::route('/create'),
            'view'   => ViewAssistant::route('/{record}'),
            'edit'   => EditAssistant::route('/{record}/edit'),
        ];
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
        return new RecordLimit(Assistant::LIMIT_KEY, Assistant::query()->count(), 'staff.assistants.limit');
    }

    public static function isLimitReached(): bool
    {
        return self::limit()->reached;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    /**
     * @return Builder<Assistant>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = Auth::user();

        if (! $user instanceof User) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $q): Builder => $q->whereKey($user->getKey()));
    }
}
