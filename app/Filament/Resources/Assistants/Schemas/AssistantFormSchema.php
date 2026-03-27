<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class AssistantFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('staff.assistants.fields.name'))
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label(__('staff.assistants.fields.is_active'))
                    ->default(true),
                Textarea::make('fallback_message')
                    ->label(__('staff.assistants.fields.fallback_message'))
                    ->rows(3)
                    ->columnSpanFull(),
                Select::make('default_flow_id')
                    ->label(__('staff.assistants.fields.default_flow'))
                    ->options([])
                    ->nullable()
                    ->disabled()
                    ->helperText(__('staff.assistants.fields.default_flow_help')),
                KeyValue::make('settings')
                    ->label(__('staff.assistants.fields.settings'))
                    ->keyLabel(__('staff.assistants.fields.settings_key'))
                    ->valueLabel(__('staff.assistants.fields.settings_value'))
                    ->addActionLabel(__('staff.assistants.fields.settings_add'))
                    ->columnSpanFull(),
            ]);
    }
}
