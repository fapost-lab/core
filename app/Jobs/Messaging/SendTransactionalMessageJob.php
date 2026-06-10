<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use FAPost\Foundation\Messaging\MessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Queue job responsible for high-priority transactional outbound deliveries.
 *
 * Delivery runs inside the owning tenant's context (resolved from the envelope's
 * tenantId) so any tenant-aware step in the pipeline — delivery logging, contact
 * bookkeeping — operates on the correct schema instead of failing at runtime.
 */
final class SendTransactionalMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  OutboundMessage  $message  Fully prepared outbound message envelope.
     */
    public function __construct(
        public readonly OutboundMessage $message,
    ) {
        $this->onQueue('messaging.transactional');
    }

    /**
     * Deliver the message and surface non-duplicate failures to Laravel retries.
     */
    public function handle(
        MessageSenderInterface $sender,
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
    ): void {
        $tenant = $tenants->getById($this->message->tenantId);

        $switcher->runForTenant($tenant, function () use ($sender): void {
            $result = $sender->send($this->message);

            if ($result->duplicate) {
                Log::info('Duplicate transactional message detected and skipped.', [
                    'idempotency_key' => $this->message->idempotencyKey,
                    'channel_id'      => $this->message->channelId,
                    'chat_id'         => $this->message->chatId,
                ]);

                return;
            }

            if (! $result->sent) {
                throw new RuntimeException($result->error ?? 'Transactional message delivery failed.');
            }
        });
    }
}
