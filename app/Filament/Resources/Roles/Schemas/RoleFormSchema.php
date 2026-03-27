<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class RoleFormSchema
{
    public static function configure(Schema $schema, ?Role $record = null): Schema
    {
        $sections = [];
        foreach (Permission::groupedByGroup() as $group => $cases) {
            $sections[] = Section::make(__('staff.permission_groups.' . $group))
                ->schema([
                    CheckboxList::make('permission_groups.' . $group)
                        ->hiddenLabel()
                        ->default([])
                        ->options(
                            collect($cases)->mapWithKeys(static fn (Permission $c) => [
                                $c->value => __('staff.permissions.' . $c->value),
                            ])->all(),
                        )
                        ->columns(4)
                        ->bulkToggleable(),
                ]);
        }

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('staff.roles.fields.name'))
                    ->required()
                    ->maxLength(255)
                    ->disabled((bool) ($record?->is_system)),
                TextInput::make('display_name')
                    ->label(__('staff.roles.fields.display_name'))
                    ->maxLength(255),
                ...$sections,
            ]);
    }
}
