<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowGroups\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class FlowGroupFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('assistant.flow_groups.fields.name'))
                ->required()
                ->maxLength(255),
        ]);
    }
}
