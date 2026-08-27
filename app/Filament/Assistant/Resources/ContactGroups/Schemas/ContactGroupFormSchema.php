<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactGroups\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class ContactGroupFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('contact_group.fields.name'))
                ->required()
                ->maxLength(255),

            Textarea::make('description')
                ->label(__('contact_group.fields.description'))
                ->rows(3)
                ->maxLength(1000),
        ]);
    }
}
