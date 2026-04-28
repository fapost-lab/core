<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Tables;

use App\Domains\Assistant\Models\Assistant;
use App\Filament\Resources\Assistants\Pages\ViewAssistant;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

final class AssistantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('staff.assistants.fields.name'))
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label(__('staff.assistants.fields.is_active'))
                    ->boolean(),
                TextColumn::make('default_language')
                    ->label(__('staff.assistants.fields.default_language'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('available_languages')
                    ->label(__('staff.assistants.fields.available_languages'))
                    ->badge()
                    ->color('gray')
                    ->separator(','),
                TextColumn::make('updated_at')
                    ->label(__('staff.assistants.table.updated_at'))
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('manage')
                    ->label(__('staff.assistants.actions.manage'))
                    ->url(fn (Assistant $record): string => route('filament.assistant.home', ['tenant' => $record]))
                    ->visible(fn (Assistant $record): bool => Gate::allows('view', $record)),
                ViewAction::make()
                    ->url(fn (Assistant $record): string => ViewAssistant::getUrl(['record' => $record])),
            ]);
    }
}
