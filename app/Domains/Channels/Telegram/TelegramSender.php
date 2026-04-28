<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use FAPost\Foundation\Messaging\DeliveryResult;
use FAPost\Foundation\Messaging\OutboundMessage;
use FAPost\Foundation\Messaging\ProviderSenderInterface;

/**
 * Telegram outbound sender used by the generic messaging engine.
 */
final readonly class TelegramSender implements ProviderSenderInterface
{
    public function __construct(
        private TelegramBotApiClientFactory $clientFactory,
        private TelegramDeliveryResolver $deliveryResolver,
    ) {
    }

    /**
     * Deliver one outbound message using the resolved Telegram transport token.
     */
    public function deliver(OutboundMessage $message): DeliveryResult
    {
        $token = (string)($message->transportToken ?? '');

        if ('' === $token) {
            return new DeliveryResult(sent: false, error: 'Telegram transport token is missing.');
        }

        $client = $this->clientFactory->make($token);

        try {
            $response = $this->deliveryResolver
                ->resolve($message->payload->type)
                ->deliver($client, $message);
        } catch (TelegramApiException $exception) {
            return new DeliveryResult(sent: false, error: $exception->getMessage());
        }

        return new DeliveryResult(
            sent: true,
            providerMessageId: isset($response['result']['message_id']) ? (string)$response['result']['message_id'] : null,
        );
    }
}
