<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Messaging\ProcessingIndicatorHandle;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Fapost\Foundation\Messaging\TypingCapableProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Telegram outbound sender used by the generic messaging engine.
 *
 * Implements {@see TypingCapableProviderInterface} as well — Telegram supports
 * a "typing" chat action that auto-clears after ~5 seconds. Indicator failures
 * are swallowed (logged at debug level) since they must never break delivery.
 */
final readonly class TelegramSender implements ProviderSenderInterface, TypingCapableProviderInterface
{
    private const string PROVIDER_ID = 'telegram';

    public function __construct(
        private TelegramBotApiClientFactory $clientFactory,
        private TelegramDeliveryResolver $deliveryResolver,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Deliver one outbound message using the bot token of the channel it is sent through.
     */
    public function deliver(OutboundMessage $message, ChannelInterface $channel): DeliveryResult
    {
        $token = $this->tokenOf($channel);

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

    public function indicateProcessing(string $chatId, ChannelInterface $channel): ProcessingIndicatorHandle
    {
        $this->safeChatAction($chatId, $channel);

        return new ProcessingIndicatorHandle(
            providerId: self::PROVIDER_ID,
            chatId: $chatId,
        );
    }

    public function refreshProcessing(ProcessingIndicatorHandle $handle, ChannelInterface $channel): void
    {
        $this->safeChatAction($handle->chatId, $channel);
    }

    public function stopProcessing(ProcessingIndicatorHandle $handle, ChannelInterface $channel): void
    {
        // No-op for Telegram: indicator auto-clears after ~5s or on next sent message.
    }

    private function tokenOf(ChannelInterface $channel): string
    {
        $token = $channel->getConfig()['token'] ?? null;

        return is_string($token) ? $token : '';
    }

    private function safeChatAction(string $chatId, ChannelInterface $channel): void
    {
        $token = $this->tokenOf($channel);

        if ('' === $token) {
            return;
        }

        try {
            $this->clientFactory->make($token)->sendChatAction($chatId, 'typing');
        } catch (Throwable $exception) {
            $this->logger->debug('telegram.chat_action_failed', [
                'chat_id' => $chatId,
                'error'   => $exception->getMessage(),
            ]);
        }
    }
}
