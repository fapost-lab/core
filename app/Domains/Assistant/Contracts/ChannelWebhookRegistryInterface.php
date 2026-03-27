<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Contracts;

use App\Domains\Assistant\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantInterface;

/**
 * Application port for assistant/channel webhook routing cache (Redis). Bind the concrete implementation in the service provider; depend on this interface from services, provisioning, and tests.
 */
interface ChannelWebhookRegistryInterface
{
    public function set(Channel $channel, TenantInterface $tenant): void;

    public function remove(string $webhookPublicHash): void;

    public function warmup(TenantInterface $tenant): void;
}
