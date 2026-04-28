<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('staff.users.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label(__('staff.users.fields.phone'))
                    ->tel()
                    ->maxLength(255)
                    ->nullable(),
                TextInput::make('email')
                    ->label(__('staff.users.fields.email'))
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('password')
                    ->label(__('staff.users.fields.password'))
                    ->password()
                    ->revealable()
                    ->visible(fn(string $operation): bool => 'edit' === $operation)
                    ->dehydrated(fn(?string $state): bool => filled($state))
                    ->maxLength(255),
                Select::make('role_id')
                    ->label(__('staff.users.fields.role'))
                    ->options(fn(): array => self::rolesForActor())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->visible(fn(string $operation): bool => 'create' === $operation),
                Select::make('roles')
                    ->label(__('staff.users.fields.roles'))
                    ->relationship(
                        name: 'roles',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn($query) => $query
                            ->where('guard_name', 'web')
                            ->where('priority', '<', self::actorMaxPriority()),
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->visible(function (string $operation, ?User $record): bool {
                        if ('edit' !== $operation || null === $record) {
                            return false;
                        }

                        /** @var User|null $actor */
                        $actor = Auth::user();

                        return null !== $actor && $actor->can('updateRoles', $record);
                    }),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    private static function rolesForActor(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where('priority', '<', self::actorMaxPriority())
            ->orderBy('priority', 'desc')
            ->pluck('name', 'id')
            ->all();
    }

    private static function actorMaxPriority(): int
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        return null !== $actor ? Role::maxPriority($actor->roles) : 0;
    }
}
