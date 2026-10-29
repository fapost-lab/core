<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

/**
 * Takes down, at the provider, the channel webhooks of the tenant whose context is current. Implemented by the
 * channels domain; Tenancy only calls it when a tenant is removed completely, while the bot tokens still exist.
 *
 * A provider that refuses never stops the removal: an implementation logs and goes on. Safe to repeat.
 */
interface ChannelWebhookRemoverInterface
{
    /**
     * @return int how many webhooks were taken down
     */
    public function removeAll(string $tenantId): int;
}
