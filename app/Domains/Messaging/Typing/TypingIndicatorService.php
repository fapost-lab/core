<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Typing;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use FAPost\Foundation\Messaging\TypingCapableProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Discovers typing-capable channel providers via the {@see ChannelRegistry}
 * and starts a {@see TypingSession} bound to the resolved transport token.
 * Channels whose provider does not implement
 * {@see TypingCapableProviderInterface} silently get a {@code null} session;
 * callers treat null as a no-op (no provider, no indicator).
 *
 * Provider exceptions during start are swallowed — failure to show an
 * indicator is never fatal to message processing (per ADR Message Routing).
 */
final readonly class TypingIndicatorService
{
    public function __construct(
        private ChannelRegistryInterface $channelRegistry,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Start a typing indicator for a chat. Returns null when:
     *   • the channel has no registered sender, or
     *   • the sender is not typing-capable, or
     *   • starting the indicator threw and was swallowed.
     */
    public function start(
        ChannelTypeEnum|string $channelType,
        string $chatId,
        string $transportToken,
    ): ?TypingSession {
        $sender = $this->channelRegistry->sender($channelType);

        if ( ! $sender instanceof TypingCapableProviderInterface) {
            return null;
        }

        try {
            $handle = $sender->indicateProcessing($chatId, $transportToken);
        } catch (Throwable $exception) {
            $this->logger->debug('typing.start_failed', [
                'channel' => $channelType instanceof ChannelTypeEnum ? $channelType->value : $channelType,
                'chat_id' => $chatId,
                'error'   => $exception->getMessage(),
            ]);

            return null;
        }

        return new TypingSession(
            provider: $sender,
            handle: $handle,
            transportToken: $transportToken,
            logger: $this->logger,
        );
    }
}
