<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Jobs;

use App\Domains\Conversation\Contracts\ConversationStoreInterface;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a provider delivery-status update (WhatsApp sent/delivered/read) to a
 * previously-logged outbound message, in the owning tenant's schema. Low-priority
 * alongside message persistence.
 */
final class UpdateConversationDeliveryStatusJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $providerMessageId,
        public readonly DeliveryStatus $status,
    ) {
        $this->onQueue('messaging.logging');
    }

    public function handle(
        ConversationStoreInterface $store,
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
    ): void {
        $tenant = $tenants->getById($this->tenantId);

        $switcher->runForTenant($tenant, function () use ($store): void {
            $store->updateStatus($this->providerMessageId, $this->status);
        });
    }
}
