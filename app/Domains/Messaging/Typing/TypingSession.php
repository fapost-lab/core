<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Typing;

use Fapost\Foundation\Messaging\ProcessingIndicatorHandle;
use Fapost\Foundation\Messaging\TypingCapableProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Live "processing" indicator session held while a flow is executing. Wraps
 * the per-provider {@see ProcessingIndicatorHandle} together with the
 * transport token required to refresh / stop the indicator without leaking
 * channel internals to callers.
 *
 * All operations are best-effort: provider exceptions are caught and logged,
 * never propagated. Indicator failures must never break message delivery.
 */
final readonly class TypingSession
{
    public function __construct(
        public TypingCapableProviderInterface $provider,
        public ProcessingIndicatorHandle $handle,
        public string $transportToken,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Reset the indicator's TTL by re-issuing the start signal. Called by
     * the lock heartbeat tick (every 4 s for Telegram's 5 s TTL) and before
     * long-running nodes ({@code call}, {@code rag_query}).
     */
    public function refresh(): void
    {
        try {
            $this->provider->refreshProcessing($this->handle, $this->transportToken);
        } catch (Throwable $exception) {
            $this->logger->debug('typing.refresh_failed', [
                'provider' => $this->handle->providerId,
                'chat_id'  => $this->handle->chatId,
                'error'    => $exception->getMessage(),
            ]);
        }
    }

    public function stop(): void
    {
        try {
            $this->provider->stopProcessing($this->handle, $this->transportToken);
        } catch (Throwable $exception) {
            $this->logger->debug('typing.stop_failed', [
                'provider' => $this->handle->providerId,
                'chat_id'  => $this->handle->chatId,
                'error'    => $exception->getMessage(),
            ]);
        }
    }
}
