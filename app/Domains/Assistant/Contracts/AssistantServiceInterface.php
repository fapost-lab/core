<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Contracts;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;

interface AssistantServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(TenantInterface $tenant, array $data): Assistant;

    /**
     *
     * Changing {@see Assistant::$is_active} here updates the assistant row only. It does not cascade to channels or
     * Redis webhook routing; use {@see deactivate}/{@see activate} for assistant lifecycle, and per-channel
     * {@see ChannelServiceInterface::reactivate} (or {@see ChannelServiceInterface::update} with {@code is_active => true})
     * when channels should register in {@see ChannelWebhookRegistryInterface} again.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Assistant $assistant, array $data): Assistant;

    /**
     * Sets the assistant active flag. Does not reactivate channels or write Redis; use {@see ChannelServiceInterface::reactivate}
     * (or {@see ChannelServiceInterface::update}) per channel when webhooks should be served again.
     */
    public function activate(Assistant $assistant): void;

    /**
     * Deactivate the assistant and cascade to channels.
     *
     * Ensures each channel becomes inactive and its Redis webhook routing entry is removed.
     */
    public function deactivate(Assistant $assistant): void;
}
