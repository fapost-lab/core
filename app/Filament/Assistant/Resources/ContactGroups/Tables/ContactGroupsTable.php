<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactGroups\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ContactGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('contact_group.fields.name'))
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('description')
                    ->label(__('contact_group.fields.description'))
                    ->placeholder('—')
                    ->limit(60),

                TextColumn::make('contacts_count')
                    ->label(__('contact_group.fields.contacts_count'))
                    ->counts('contacts')
                    ->badge()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label(__('contact_group.fields.created_at'))
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->paginated([25, 50, 100]);
    }
}
