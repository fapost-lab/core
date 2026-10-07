<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Domains\Staff\Models\User;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Support\RecordLimit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getNavigationGroup(): ?string
    {
        return __('staff.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('staff.users.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('staff.users.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
        return new RecordLimit(User::LIMIT_KEY, User::countForLimit(), 'staff.users.limit');
    }

    public static function isLimitReached(): bool
    {
        return self::limit()->reached;
    }

    public static function getRelations(): array
    {
        return [

        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit'   => EditUser::route('/{record}/edit'),
        ];
    }
}
