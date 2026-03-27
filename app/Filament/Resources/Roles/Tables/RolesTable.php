<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('staff.roles.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('display_name')
                    ->label(__('staff.roles.table.display_name'))
                    ->searchable(),
                IconColumn::make('is_system')
                    ->label(__('staff.roles.table.system'))
                    ->boolean(),
                TextColumn::make('permissions_count')
                    ->label(__('staff.roles.table.permissions'))
                    ->counts('permissions')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
