<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use App\Jobs\Messaging\SyncChannelWebhookJob;

/**
 * Moves already-registered channels onto the ingress host the configuration
 * currently selects.
 *
 * Migration is intentionally incremental. Both ingress runtimes stay valid, so a
 * channel left on the old host keeps working indefinitely; there is no window to
 * race against and no reason to re-register thousands of channels at once when
 * every one of them costs a rate-limited provider call.
 */
final readonly class IngressMigrator
{
    public function __construct(
        private WebhookRegistryReaderInterface $reader,
        private WebhookUrlGenerator $urlGenerator,
        private TenantRepositoryInterface $tenants,
        private TenantSwitcher $switcher,
    ) {
    }

    /**
     * Channels per platform whose recorded ingress host differs from the configured one.
     *
     * @return array<string, int>
     */
    public function drift(): array
    {
        $drift = [];

        foreach (ChannelTypeEnum::cases() as $type) {
            $count = $this->reader->countIngressDrift(
                $type->value,
                $this->urlGenerator->baseFor($type->value),
            );

            if ($count > 0) {
                $drift[$type->value] = $count;
            }
        }

        return $drift;
    }

    /**
     * Queue re-registration for one batch of drifted channels of a platform.
     *
     * Returns the number of channels queued, which can be lower than the batch
     * size: a registry row whose tenant or channel no longer exists is skipped
     * rather than retried forever.
     */
    public function migrate(string $platform, int $limit): int
    {
        $rows = $this->reader->findIngressDrift(
            $platform,
            $this->urlGenerator->baseFor($platform),
            $limit,
        );

        $queued = 0;

        foreach ($this->groupByTenant($rows) as $tenantId => $tenantRows) {
            $tenant = $this->tenants->findById((string) $tenantId);

            if (null === $tenant) {
                continue;
            }

            $queued += (int) $this->switcher->runForTenant(
                $tenant,
                fn (): int => $this->queueForTenant($tenantRows, (string) $tenantId, $tenant->getSchemaName()),
            );
        }

        return $queued;
    }

    /**
     * @param  list<object>  $rows
     *
     * @return array<string, list<object>>
     */
    private function groupByTenant(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->tenant_id][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  list<object>  $rows
     */
    private function queueForTenant(array $rows, string $tenantId, string $schema): int
    {
        $channels = Channel::query()
            ->whereIn('id', array_map(static fn (object $row): string => (string) $row->channel_id, $rows))
            ->get()
            ->keyBy(static fn (Channel $channel): string => (string) $channel->getKey());

        $queued = 0;

        foreach ($rows as $row) {
            $channel = $channels->get((string) $row->channel_id);

            if (null === $channel) {
                continue;
            }

            // Queued rather than run inline: each dispatch is a provider API call,
            // and the shared messaging.system queue is where that backpressure
            // is already handled. The credentials are not part of the payload (it is
            // stored in `jobs` and `failed_jobs`); the job loads them by channel id.
            SyncChannelWebhookJob::dispatch(
                tenantId: $tenantId,
                schema: $schema,
                channelId: (string) $channel->getKey(),
                channelType: $channel->type->value,
                webhookPublicHash: $channel->webhook_public_hash,
                token: null,
                secretToken: null,
                config: is_array($channel->config) ? $channel->config : [],
                register: true,
            );

            $queued++;
        }

        return $queued;
    }
}
