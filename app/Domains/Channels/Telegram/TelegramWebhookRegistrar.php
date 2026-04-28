<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Contracts\TelegramBotIdentityStoreInterface;
use App\Domains\Channels\Telegram\Dto\SetWebhookDto;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use FAPost\Foundation\Channel\WebhookRegistrarInterface;
use FAPost\Foundation\Channel\WebhookRegistrationPayload;

/**
 * Registers Telegram provider-side webhooks for configured assistant channels.
 */
final readonly class TelegramWebhookRegistrar implements WebhookRegistrarInterface
{
    public function __construct(
        private TelegramBotApiClientFactory $clientFactory,
        private WebhookUrlGenerator $webhookUrlGenerator,
        private TelegramBotIdentityStoreInterface $identityStore,
    ) {
    }

    /**
     * Create or refresh the Telegram webhook for the given channel.
     */
    public function register(WebhookRegistrationPayload $payload): void
    {
        $client         = $this->clientFactory->make($payload->token);
        $allowedUpdates = is_array($payload->config['allowed_updates'] ?? null)
            ? $payload->config['allowed_updates']
            : null;
        $maxConnections = max(1, min(100, (int)($payload->config['max_connections'] ?? 40)));

        $client->setWebhook(
            new SetWebhookDto(
                url: $this->webhookUrlGenerator->forChannel('telegram', $payload->webhookPublicHash),
                secretToken: $payload->secretToken,
                allowedUpdates: $allowedUpdates,
                maxConnections: $maxConnections,
            )
        );

        $bot = $client->getMe();

        $this->identityStore->saveUsername(
            $payload->channelId,
            is_string($bot['result']['username'] ?? null) ? $bot['result']['username'] : null,
        );
    }

    /**
     * Remove the Telegram webhook for the given channel.
     */
    public function deregister(WebhookRegistrationPayload $payload): void
    {
        $this->clientFactory
            ->make($payload->token)
            ->deleteWebhook();
    }
}
