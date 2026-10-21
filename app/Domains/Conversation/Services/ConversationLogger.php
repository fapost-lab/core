<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Jobs\PersistConversationMessageJob;
use App\Domains\Conversation\Jobs\UpdateConversationDeliveryStatusJob;
use App\Domains\Conversation\Settings\ConversationSettings;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Write-port implementation: enqueues transcript persistence without blocking the
 * hot path. Mirrors {@see \App\Domains\Flow\History\DefaultHistoryWriter} — logging
 * is secondary, so any dispatch failure is swallowed and logged, never rethrown.
 *
 * The tenant's `conversation_logging` setting is checked here only when the tenant in context is the entry's own, so a
 * disabled tenant costs no job; the persist job re-checks inside the entry's tenant, which is the authority when the
 * entry is logged from another tenant's context.
 */
final readonly class ConversationLogger implements ConversationLoggerInterface
{
    public function __construct(
        private ConversationSettings $settings,
        private LoggerInterface $logger,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function log(MessageLogEntry $entry): void
    {
        try {
            if ($this->tenantContext->isResolved()
                && (string) $this->tenantContext->get()->getId() === $entry->tenantId
                && ! $this->settings->loggingEnabled()) {
                return;
            }

            PersistConversationMessageJob::dispatch($entry);
        } catch (Throwable $exception) {
            $this->logger->warning('conversation.log.dispatch_failed', [
                'tenant_id'  => $entry->tenantId,
                'contact_id' => $entry->contactId,
                'direction'  => $entry->direction->value,
                'error'      => $exception->getMessage(),
            ]);
        }
    }

    public function updateDeliveryStatus(string $providerMessageId, DeliveryStatus $status): void
    {
        try {
            if (! $this->settings->loggingEnabled()) {
                return;
            }

            // Delivery-status webhooks are handled inside the owning tenant's
            // scope; capture the tenant id so the async job can switch schema.
            $tenantId = $this->tenantContext->isResolved()
                ? $this->tenantContext->get()->getId()
                : null;

            if (null === $tenantId) {
                return;
            }

            UpdateConversationDeliveryStatusJob::dispatch($tenantId, $providerMessageId, $status);
        } catch (Throwable $exception) {
            $this->logger->warning('conversation.log.status_dispatch_failed', [
                'provider_message_id' => $providerMessageId,
                'status'              => $status->value,
                'error'               => $exception->getMessage(),
            ]);
        }
    }
}
