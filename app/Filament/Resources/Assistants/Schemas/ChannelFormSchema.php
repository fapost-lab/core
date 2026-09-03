<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Schemas;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

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
                    ->live()
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
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    // The value is arbitrary shared entropy — nobody should be
                    // inventing it by hand. Telegram accepts A-Z a-z 0-9 _ -,
                    // 1..256 chars; Str::random() stays inside that alphabet.
                    ->suffixAction(
                        Action::make('generateSecretToken')
                            ->label(__('staff.channels.actions.generate_secret_token'))
                            ->icon(Heroicon::OutlinedSparkles)
                            ->action(function (Set $set): void {
                                $set('secret_token', Str::random(48));
                            })
                    ),
                Toggle::make('is_active')
                    ->label(__('staff.channels.fields.is_active'))
                    ->default(true),
                // Generated on create and rotated through its own action — shown
                // here read-only so staff can copy the value when debugging the
                // provider-side webhook.
                TextInput::make('webhook_public_hash')
                    ->label(__('staff.channels.fields.webhook_hash'))
                    ->readOnly()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => 'create' !== $operation)
                    ->helperText(__('staff.channels.fields.webhook_hash_help')),
                Section::make(__('staff.channels.fields.config'))
                    ->components([
                        Select::make('config.allowed_updates')
                            ->label(__('staff.channels.fields.allowed_updates'))
                            ->options(self::telegramAllowedUpdates())
                            ->multiple()
                            ->searchable()
                            ->preload()
                            // 20+ update types: picking them one by one is the
                            // common case ("give me everything"), so make it
                            // one click — and offer the way back out too.
                            ->hintActions([
                                Action::make('selectAllAllowedUpdates')
                                    ->label(__('staff.channels.actions.select_all'))
                                    ->icon(Heroicon::OutlinedCheckCircle)
                                    ->link()
                                    ->action(static function (Set $set): void {
                                        $set('config.allowed_updates', array_keys(self::telegramAllowedUpdates()));
                                    }),
                                Action::make('clearAllowedUpdates')
                                    ->label(__('staff.channels.actions.clear_all'))
                                    ->icon(Heroicon::OutlinedXCircle)
                                    ->link()
                                    ->color('gray')
                                    ->action(static function (Set $set): void {
                                        $set('config.allowed_updates', []);
                                    }),
                            ])
                            ->dehydrated(
                                fn (Get $get): bool => ChannelTypeEnum::Telegram->value === self::resolveChannelType(
                                    $get
                                )
                            )
                            ->helperText(__('staff.channels.fields.allowed_updates_help')),
                        TextInput::make('config.max_connections')
                            ->label(__('staff.channels.fields.max_connections'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(40)
                            ->dehydrated(
                                fn (Get $get): bool => ChannelTypeEnum::Telegram->value === self::resolveChannelType(
                                    $get
                                )
                            )
                            ->afterStateHydrated(
                                fn (TextInput $component, mixed $state): TextInput => $component->state($state ?? 40)
                            )
                            ->helperText(__('staff.channels.fields.max_connections_help')),
                    ])
                    ->columnSpanFull()
                    ->visible(
                        fn (Get $get): bool => ChannelTypeEnum::Telegram->value === self::resolveChannelType($get)
                    ),
                KeyValue::make('config_kv')
                    ->label(__('staff.channels.fields.config'))
                    ->keyLabel(__('staff.channels.fields.config_key'))
                    ->valueLabel(__('staff.channels.fields.config_value'))
                    ->addActionLabel(__('staff.channels.fields.config_add'))
                    ->visible(fn (Get $get): bool => ChannelTypeEnum::Telegram->value !== self::resolveChannelType($get))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function telegramAllowedUpdates(): array
    {
        $events = [
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post',
            'business_connection',
            'business_message',
            'edited_business_message',
            'deleted_business_messages',
            'message_reaction',
            'message_reaction_count',
            'inline_query',
            'chosen_inline_result',
            'callback_query',
            'shipping_query',
            'pre_checkout_query',
            'purchased_paid_media',
            'poll',
            'poll_answer',
            'my_chat_member',
            'chat_member',
            'chat_join_request',
            'chat_boost',
            'removed_chat_boost',
            'managed_bot',
        ];

        $options = [];

        foreach ($events as $event) {
            $translationKey = "staff.channels.telegram_updates.{$event}";
            $translated     = __($translationKey);

            $options[$event] = $translated === $translationKey
                ? Str::headline($event)
                : $translated;
        }

        return $options;
    }

    private static function resolveChannelType(Get $get): ?string
    {
        $type = $get('type') ?? $get('../../type') ?? $get('../type');

        if ($type instanceof ChannelTypeEnum) {
            return $type->value;
        }

        return is_string($type) ? $type : null;
    }
}
