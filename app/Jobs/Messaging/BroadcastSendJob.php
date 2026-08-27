<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Queue job responsible for low-priority broadcast outbound deliveries.
 *
 * Delivery runs inside the owning tenant's context (resolved from the envelope's
 * tenantId) so future per-recipient bookkeeping — broadcast_recipients status,
 * delivery logs — lands in the correct schema without reworking the job contract.
 */
final class BroadcastSendJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  OutboundMessage  $message  Fully prepared outbound message envelope.
     */
    public function __construct(
        public readonly OutboundMessage $message,
    ) {
        $this->onQueue('messaging.broadcast');
    }

    /**
     * Deliver the message and bubble non-duplicate failures for queue retries.
     */
    public function handle(
        MessageSenderInterface $sender,
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
    ): void {
        $tenant = $tenants->getById($this->message->tenantId);

        $switcher->runForTenant($tenant, function () use ($sender): void {
            $result = $sender->send($this->message);

            if (! $result->sent && ! $result->duplicate) {
                throw new RuntimeException($result->error ?? 'Broadcast message delivery failed.');
            }
        });
    }
}
