<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Channel\Settings\ManagedChannelSettingsInterface;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Channel\WebhookRegistrationPayload;

/**
 * The fixture registrar has no provider to call: the account name the provider would answer with is the token, which
 * it hands to Core through the port, as a connector does after the provider answered.
 */
final readonly class EchoWebhookRegistrar implements WebhookRegistrarInterface
{
    public function __construct(private ManagedChannelSettingsInterface $managedSettings)
    {
    }

    public function register(WebhookRegistrationPayload $payload): void
    {
        $this->managedSettings->report($payload->channelId, ['account_name' => $payload->token]);
    }

    public function deregister(WebhookRegistrationPayload $payload): void
    {
    }
}
