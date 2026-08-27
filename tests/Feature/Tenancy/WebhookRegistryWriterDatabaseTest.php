<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class WebhookRegistryWriterDatabaseTest extends FeatureTestCase
{
    public function test_write_persists_full_length_hash_and_uuid_identifiers_to_landlord_db(): void
    {
        Redis::shouldReceive('set')->once()->andReturnTrue();

        $tenant      = Tenant::query()->firstOrFail();
        $hash        = Str::random(48);
        $assistantId = (string) Str::uuid();
        $channelId   = (string) Str::uuid();

        $this->app->make(WebhookRegistryWriter::class)->write(
            $hash,
            $tenant,
            $assistantId,
            $channelId,
            'telegram',
            'test-secret',
        );

        $this->assertDatabaseHas('webhook_registry', [
            'webhook_public_hash' => $hash,
            'tenant_id'           => $tenant->getKey(),
            'assistant_id'        => $assistantId,
            'channel_id'          => $channelId,
            'platform'            => 'telegram',
            'secret_token'        => 'test-secret',
        ], 'landlord');

        $storedHash = DB::connection('landlord')
            ->table('webhook_registry')
            ->where('webhook_public_hash', $hash)
            ->value('webhook_public_hash');

        $this->assertSame(48, mb_strlen((string) $storedHash));
    }

    public function test_record_ingress_stores_and_clears_the_registered_host(): void
    {
        $hash   = $this->seedEntry();
        $writer = $this->app->make(WebhookRegistryWriter::class);

        $writer->recordIngress($hash, 'https://webhook.example.com');
        $this->assertSame('https://webhook.example.com', $this->storedIngress($hash));

        $writer->recordIngress($hash, null);
        $this->assertNull($this->storedIngress($hash));
    }

    /**
     * Re-writing a registry entry happens on every channel save, while the ingress
     * host only changes when a provider accepts a new URL. If a plain write reset
     * the column, every save would look like fresh drift and the migration report
     * would never converge.
     */
    public function test_write_preserves_a_previously_recorded_ingress_host(): void
    {
        Redis::shouldReceive('set')->andReturnTrue();

        $tenant = Tenant::query()->firstOrFail();
        $hash   = $this->seedEntry();

        $this->app->make(WebhookRegistryWriter::class)->recordIngress($hash, 'https://webhook.example.com');

        $this->app->make(WebhookRegistryWriter::class)->write(
            $hash,
            $tenant,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'telegram',
            'rotated-secret',
        );

        $this->assertSame('https://webhook.example.com', $this->storedIngress($hash));
    }

    public function test_reader_reports_drift_for_absent_and_mismatched_hosts(): void
    {
        $reader = $this->app->make(WebhookRegistryReaderInterface::class);

        $unregistered = $this->seedEntry();
        $onGateway    = $this->seedEntry();
        $onLaravel    = $this->seedEntry();

        $writer = $this->app->make(WebhookRegistryWriter::class);
        $writer->recordIngress($onGateway, 'https://webhook.example.com');
        $writer->recordIngress($onLaravel, 'https://app.example.com');

        $this->assertSame(2, $reader->countIngressDrift('telegram', 'https://webhook.example.com'));

        $drifted = array_map(
            static fn (object $row): string => (string) $row->webhook_public_hash,
            $reader->findIngressDrift('telegram', 'https://webhook.example.com', 10),
        );

        $this->assertContains($unregistered, $drifted, 'A never-registered host must count as drift.');
        $this->assertContains($onLaravel, $drifted);
        $this->assertNotContains($onGateway, $drifted);
    }

    public function test_reader_bounds_the_drift_batch(): void
    {
        $this->seedEntry();
        $this->seedEntry();
        $this->seedEntry();

        $batch = $this->app->make(WebhookRegistryReaderInterface::class)
            ->findIngressDrift('telegram', 'https://webhook.example.com', 2);

        $this->assertCount(2, $batch);
    }

    private function seedEntry(): string
    {
        Redis::shouldReceive('set')->andReturnTrue();

        $hash = Str::random(48);

        $this->app->make(WebhookRegistryWriter::class)->write(
            $hash,
            Tenant::query()->firstOrFail(),
            (string) Str::uuid(),
            (string) Str::uuid(),
            'telegram',
            'test-secret',
        );

        return $hash;
    }

    private function storedIngress(string $hash): ?string
    {
        $value = DB::connection('landlord')
            ->table('webhook_registry')
            ->where('webhook_public_hash', $hash)
            ->value('ingress_base_url');

        return null === $value ? null : (string) $value;
    }
}
