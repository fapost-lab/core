<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Schemas;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class ChannelFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('staff.channels.fields.type'))
                    ->options(ChannelTypeEnum::options())
                    ->required()
                    ->native(false),
                TextInput::make('token')
                    ->label(__('staff.channels.fields.token'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => 'create' === $operation)
                    ->maxLength(65535)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                TextInput::make('secret_token')
                    ->label(__('staff.channels.fields.secret_token'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => 'create' === $operation)
                    ->maxLength(65535)
                    ->helperText(__('staff.channels.fields.secret_token_help'))
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Toggle::make('is_active')
                    ->label(__('staff.channels.fields.is_active'))
                    ->default(true),
                KeyValue::make('config')
                    ->label(__('staff.channels.fields.config'))
                    ->keyLabel(__('staff.channels.fields.config_key'))
                    ->valueLabel(__('staff.channels.fields.config_value'))
                    ->addActionLabel(__('staff.channels.fields.config_add'))
                    ->columnSpanFull(),
            ]);
    }
}
