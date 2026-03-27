<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Contracts\ChannelServiceInterface;
use App\Domains\Assistant\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class AssistantService implements AssistantServiceInterface
{
    public function __construct(
        private ChannelServiceInterface $channelService,
        private ChannelWebhookRegistryInterface $channelWebhookRegistry,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(TenantInterface $tenant, array $data): Assistant
    {
        $assistant = new Assistant([
            'tenant_id'        => $tenant->getId(),
            'name'             => (string) $data['name'],
            'is_active'        => (bool) ($data['is_active'] ?? true),
            'fallback_message' => isset($data['fallback_message']) ? (string) $data['fallback_message'] : null,
            'default_flow_id'  => isset($data['default_flow_id']) && '' !== (string) $data['default_flow_id']
                ? (string) $data['default_flow_id']
                : null,
            'settings' => is_array($data['settings'] ?? null) ? $data['settings'] : [],
        ]);
        $assistant->save();

        return $assistant;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Assistant $assistant, array $data): Assistant
    {
        $allowed = Arr::only($data, [
            'name',
            'is_active',
            'fallback_message',
            'default_flow_id',
            'settings',
        ]);

        if (array_key_exists('settings', $allowed)) {
            $assistant->settings = is_array($allowed['settings']) ? $allowed['settings'] : [];
            unset($allowed['settings']);
        }

        if (array_key_exists('default_flow_id', $allowed)) {
            $v                          = $allowed['default_flow_id'];
            $assistant->default_flow_id = null !== $v && '' !== (string) $v ? (string) $v : null;
            unset($allowed['default_flow_id']);
        }

        $assistant->fill($allowed);
        $assistant->save();

        return $assistant->fresh();
    }

    public function activate(Assistant $assistant): void
    {
        $assistant->is_active = true;
        $assistant->save();
    }

    /**
     * Deactivation contract (service-level invariant, not only observer side effects):
     * every channel is deactivated via {@see ChannelServiceInterface::deactivate} and removed from
     * {@see ChannelWebhookRegistryInterface} so Redis cannot route webhooks to a deactivated assistant tree.
     */
    public function deactivate(Assistant $assistant): void
    {
        DB::transaction(function () use ($assistant): void {
            $assistant->load('channels');

            foreach ($assistant->channels as $channel) {
                $this->channelService->deactivate($channel);
                $this->channelWebhookRegistry->remove($channel->webhook_public_hash);
            }

            $assistant->is_active = false;
            $assistant->save();
        });
    }
}
