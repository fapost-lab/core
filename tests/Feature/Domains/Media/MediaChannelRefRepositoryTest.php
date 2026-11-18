<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Media\Contracts\MediaChannelRefRepositoryInterface;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Tests\Feature\FeatureTestCase;

final class MediaChannelRefRepositoryTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_the_provider_kind_is_stored_updated_and_optional(): void
    {
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));

        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'is_active'    => true,
        ]));
        $blob = MediaBlob::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'content_hash' => str_repeat('c', 64),
            'storage_path' => 'tenants/test/media/c.wav',
            'storage_disk' => 'local',
            'size'         => 10,
            'mime_type'    => 'audio/wav',
        ]);

        $refs = $this->app->make(MediaChannelRefRepositoryInterface::class);

        $refs->upsert($blob->id, (string) $channel->getKey(), 'file-1', null, 'document');
        $this->assertSame('document', $refs->find($blob->id, (string) $channel->getKey())?->provider_kind);

        // An old-style call without a kind clears it: the id then is whatever the new upload produced.
        $refs->upsert($blob->id, (string) $channel->getKey(), 'file-2', null);
        $ref = $refs->find($blob->id, (string) $channel->getKey());
        $this->assertSame('file-2', $ref?->provider_file_id);
        $this->assertNull($ref->provider_kind);
    }
}
