<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

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
}
