<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\Settings\ChannelPublicLink;
use Fapost\Foundation\Channel\Settings\ChannelSettingField;
use Fapost\Foundation\Channel\Settings\ChannelSettingsSchema;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;
use Illuminate\Support\Str;

/**
 * What the Telegram channel tells Core about itself: the settings of its webhook, the bot name the provider reports
 * and the link that opens the bot. Declared with the same Foundation contract a connector uses.
 *
 * `bot_username` is managed: {@see TelegramWebhookRegistrar} reports it through {@see \Fapost\Foundation\Channel\Settings\ManagedChannelSettingsInterface} after each webhook registration, the form
 * never sends it, and saving the form keeps it.
 */
final class TelegramChannelProfile implements ChannelProfileInterface
{
    /**
     * Telegram accepts a secret token of 1 to 256 characters from `A-Z a-z 0-9 _ -`.
     */
    public const string SECRET_TOKEN_PATTERN = '[A-Za-z0-9_-]{1,256}';

    private const string TRANSLATIONS = 'console.channels.settings.telegram';

    public function channelType(): string
    {
        return 'telegram';
    }

    public function settings(): ChannelSettingsSchema
    {
        $updates = [];

        foreach (TelegramWebhookOptions::ALLOWED_UPDATES as $update) {
            $updates[$update] = Str::headline($update);
        }

        return new ChannelSettingsSchema(
            fields: [
                new ChannelSettingField(
                    key: 'allowed_updates',
                    type: ChannelSettingType::Choices,
                    label: 'Allowed updates',
                    help: 'Telegram update types that this webhook should receive.',
                    default: [],
                    choices: $updates,
                    translationKey: self::TRANSLATIONS . '.allowed_updates',
                ),
                new ChannelSettingField(
                    key: 'max_connections',
                    type: ChannelSettingType::Integer,
                    label: 'Max connections',
                    help: 'Telegram webhook delivery parallelism, from :min to :max. Defaults to :default.',
                    default: TelegramWebhookOptions::MAX_CONNECTIONS_DEFAULT,
                    required: true,
                    min: TelegramWebhookOptions::MAX_CONNECTIONS_MIN,
                    max: TelegramWebhookOptions::MAX_CONNECTIONS_MAX,
                    translationKey: self::TRANSLATIONS . '.max_connections',
                ),
                new ChannelSettingField(
                    key: 'bot_username',
                    type: ChannelSettingType::Text,
                    label: 'Bot username',
                    max: 64,
                    managed: true,
                ),
            ],
            usesSecretToken: true,
            secretTokenPattern: self::SECRET_TOKEN_PATTERN,
        );
    }

    public function publicLink(array $config): ?ChannelPublicLink
    {
        $username = $config['bot_username'] ?? null;

        if (! is_string($username) || '' === $username) {
            return null;
        }

        return new ChannelPublicLink($username, '@' . $username, 'https://t.me/' . $username);
    }
}
