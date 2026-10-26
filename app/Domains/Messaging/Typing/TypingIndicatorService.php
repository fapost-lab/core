<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Typing;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Messaging\TypingCapableProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Discovers typing-capable channel providers via the {@see ChannelRegistry}
 * and starts a {@see TypingSession} bound to the channel it runs for.
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
        ChannelInterface $channel,
        string $chatId,
    ): ?TypingSession {
        $channelType = $channel->getType();
        $sender      = $this->channelRegistry->sender($channelType);

        if (! $sender instanceof TypingCapableProviderInterface) {
            return null;
        }

        try {
            $handle = $sender->indicateProcessing($chatId, $channel);
        } catch (Throwable $exception) {
            $this->logger->debug('typing.start_failed', [
                'channel' => $channelType,
                'chat_id' => $chatId,
                'error'   => $exception->getMessage(),
            ]);

            return null;
        }

        return new TypingSession(
            provider: $sender,
            handle: $handle,
            channel: $channel,
            logger: $this->logger,
        );
    }
}
