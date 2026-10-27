<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\Settings\ChannelPublicLink;
use Fapost\Foundation\Channel\Settings\ChannelSettingField;
use Fapost\Foundation\Channel\Settings\ChannelSettingsSchema;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;
use Fapost\Foundation\Channel\Settings\ChannelSetupCopyKind;
use Fapost\Foundation\Channel\Settings\ChannelSetupGuideInterface;
use Fapost\Foundation\Channel\Settings\ChannelSetupStep;

/**
 * The fixture channel declares a setting of every type, one the integration writes itself, and no webhook secret. Its setup guide has a step with the webhook URL, once the channel has one, and a step with a block of
 * code that names the configured region.
 */
final class EchoChannelProfile implements ChannelProfileInterface, ChannelSetupGuideInterface
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

    public function setupGuide(?string $webhookUrl, array $config): array
    {
        $region = is_string($config['region'] ?? null) ? $config['region'] : 'eu';

        return [
            new ChannelSetupStep(
                title: 'Send events to this URL',
                text: null === $webhookUrl ? 'The URL appears once the channel is created.' : 'Paste it into the Echo dashboard.',
                copy: $webhookUrl,
                copyKind: ChannelSetupCopyKind::Url,
                translationKey: 'echo.setup.url',
            ),
            new ChannelSetupStep(
                title: 'Add the subscription',
                text: 'Create it with this body.',
                copy: json_encode(['region' => $region, 'events' => ['sent']], JSON_THROW_ON_ERROR),
                copyKind: ChannelSetupCopyKind::Code,
            ),
        ];
    }
}
