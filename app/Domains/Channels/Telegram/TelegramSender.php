<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
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

    public function indicateProcessing(string $chatId, string $transportToken): ProcessingIndicatorHandle
    {
        $this->safeChatAction($chatId, $transportToken);

        return new ProcessingIndicatorHandle(
            providerId: self::PROVIDER_ID,
            chatId: $chatId,
        );
    }

    public function refreshProcessing(ProcessingIndicatorHandle $handle, string $transportToken): void
    {
        $this->safeChatAction($handle->chatId, $transportToken);
    }

    public function stopProcessing(ProcessingIndicatorHandle $handle, string $transportToken): void
    {
        // No-op for Telegram: indicator auto-clears after ~5s or on next sent message.
    }

    private function safeChatAction(string $chatId, string $transportToken): void
    {
        if ('' === $transportToken) {
            return;
        }

        try {
            $this->clientFactory->make($transportToken)->sendChatAction($chatId, 'typing');
        } catch (Throwable $exception) {
            $this->logger->debug('telegram.chat_action_failed', [
                'chat_id' => $chatId,
                'error'   => $exception->getMessage(),
            ]);
        }
    }
}
