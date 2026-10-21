<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\Settings\ChannelPublicLink;
use Fapost\Foundation\Channel\Settings\ChannelSettingField;
use Fapost\Foundation\Channel\Settings\ChannelSettingsSchema;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;

/**
 * The fixture channel declares a setting of every type, one the integration writes itself, and no webhook secret.
 */
final class EchoChannelProfile implements ChannelProfileInterface
{
    public function channelType(): string
    {
        return 'echo';
    }

    public function settings(): ChannelSettingsSchema
    {
        return new ChannelSettingsSchema([
            new ChannelSettingField('region', ChannelSettingType::Text, 'Region', 'Where the account lives.', default: 'eu', max: 8),
            new ChannelSettingField('retries', ChannelSettingType::Integer, 'Retries', default: 2, required: true, min: 0, max: 5),
            new ChannelSettingField('sandbox', ChannelSettingType::Boolean, 'Sandbox mode', default: false),
            new ChannelSettingField('mode', ChannelSettingType::Choice, 'Mode', default: 'safe', required: true, choices: ['fast' => 'Fast', 'safe' => 'Safe']),
            new ChannelSettingField('events', ChannelSettingType::Choices, 'Events', default: ['sent'], required: true, choices: ['sent' => 'Sent', 'read' => 'Read', 'failed' => 'Failed']),
            new ChannelSettingField('account_name', ChannelSettingType::Text, 'Account name', managed: true),
            new ChannelSettingField('account_id', ChannelSettingType::Integer, 'Account id', managed: true),
        ], usesSecretToken: false);
    }

    public function publicLink(array $config): ?ChannelPublicLink
    {
        $name = $config['account_name'] ?? null;

        return is_string($name) && '' !== $name ? new ChannelPublicLink($name, '@' . $name, 'https://echo.example/' . $name) : null;
    }
}
