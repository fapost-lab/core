<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\IngressMigrator;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use App\Domains\Webhook\Enums\IngressDriver;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\FeatureTestCase;

final class IngressMigratorTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webhook.base_url', 'https://app.example.com');
        config()->set('webhook.ingress.gateway_url', 'https://webhook.example.com');
    }

    public function test_drift_counts_channels_not_on_the_configured_host(): void
    {
        $this->useGateway();

        $this->seedChannel(recordedIngress: 'https://webhook.example.com');
        $this->seedChannel(recordedIngress: 'https://app.example.com');
        $this->seedChannel(recordedIngress: null);

        $this->assertSame(['telegram' => 2], $this->migrator()->drift());
    }

    public function test_no_drift_is_reported_once_every_channel_is_on_the_configured_host(): void
    {
        $this->useGateway();

        $this->seedChannel(recordedIngress: 'https://webhook.example.com');

        $this->assertSame([], $this->migrator()->drift());
    }

    /**
     * Switching the driver back must present the previously migrated channels as
     * drift again — that is what makes the rollback path observable and runnable.
     */
    public function test_switching_the_driver_back_reports_gateway_channels_as_drift(): void
    {
        $this->seedChannel(recordedIngress: 'https://webhook.example.com');

        $this->assertSame(['telegram' => 1], $this->migrator()->drift());
    }

    public function test_migrate_queues_re_registration_for_drifted_channels_only(): void
    {
        $this->useGateway();

        $stale   = $this->seedChannel(recordedIngress: 'https://app.example.com');
        $current = $this->seedChannel(recordedIngress: 'https://webhook.example.com');

        Bus::fake();

        $queued = $this->migrator()->migrate('telegram', 50);

        $this->assertSame(1, $queued);

        Bus::assertDispatched(
            SyncChannelWebhookJob::class,
            static fn (SyncChannelWebhookJob $job): bool => $job->webhookPublicHash === $stale->webhook_public_hash
                && $job->register
                && null === $job->token
                && null === $job->secretToken
                && ! str_contains(serialize($job), $stale->token),
        );

        Bus::assertNotDispatched(
            SyncChannelWebhookJob::class,
            static fn (SyncChannelWebhookJob $job): bool => $job->webhookPublicHash === $current->webhook_public_hash,
        );
    }

    /**
     * Provider re-registration is rate limited, so a run must never fan out past
     * the batch size it was given.
     */
    public function test_migrate_respects_the_batch_limit(): void
    {
        $this->useGateway();

        $this->seedChannel(recordedIngress: null);
        $this->seedChannel(recordedIngress: null);
        $this->seedChannel(recordedIngress: null);

        Bus::fake();

        $this->assertSame(2, $this->migrator()->migrate('telegram', 2));

        Bus::assertDispatchedTimes(SyncChannelWebhookJob::class, 2);
    }

    /**
     * A registry row whose channel is gone would otherwise be picked up by every
     * run forever, since nothing can ever record an ingress host for it.
     */
    public function test_migrate_skips_registry_rows_without_a_channel(): void
    {
        $this->useGateway();

        $channel = $this->seedChannel(recordedIngress: null);

        Channel::withoutEvents(static fn (): ?bool => $channel->forceDelete());

        Bus::fake();

        $this->assertSame(0, $this->migrator()->migrate('telegram', 50));

        Bus::assertNothingDispatched();
    }

    private function useGateway(): void
    {
        config()->set('webhook.ingress.driver', IngressDriver::Gateway->value);
    }

    private function seedChannel(?string $recordedIngress): Channel
    {
        Redis::shouldReceive('set')->andReturnTrue();
        Redis::shouldReceive('del')->andReturn(1);

        $tenant = Tenant::query()->firstOrFail();

        // Observer-free: the observer registers the provider webhook, which is the
        // very thing under test here and must not fire while building fixtures.
        $channel = Channel::withoutEvents(
            fn (): Channel => Channel::factory()->create(['tenant_id' => $tenant->getKey()]),
        );

        $writer = $this->app->make(WebhookRegistryWriter::class);

        $writer->write(
            $channel->webhook_public_hash,
            $tenant,
            (string) $channel->assistant_id,
            (string) $channel->getKey(),
            $channel->type->value,
            (string) $channel->getAttribute('secret_token'),
        );

        if (null !== $recordedIngress) {
            $writer->recordIngress($channel->webhook_public_hash, $recordedIngress);
        }

        return $channel;
    }

    private function migrator(): IngressMigrator
    {
        return $this->app->make(IngressMigrator::class);
    }
}
