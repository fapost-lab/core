<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Schemas;

use App\Filament\Support\ContentLanguages;
use Filament\Forms\Components\Select;
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
                Select::make('default_language')
                    ->label(__('staff.assistants.fields.default_language'))
                    ->options(ContentLanguages::options())
                    ->searchable()
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('staff.assistants.fields.is_active'))
                    ->default(true),
            ]);
    }
}
