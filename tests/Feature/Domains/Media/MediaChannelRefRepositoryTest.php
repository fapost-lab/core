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

    public function test_the_provider_kind_is_stored_as_given_updated_and_cleared_by_null(): void
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

        // An explicit null clears it: the id then is whatever the new upload produced.
        $refs->upsert($blob->id, (string) $channel->getKey(), 'file-2', null, null);
        $ref = $refs->find($blob->id, (string) $channel->getKey());
        $this->assertSame('file-2', $ref?->provider_file_id);
        $this->assertNull($ref->provider_kind);

        // An unknown kind is stored as given; DeliveryKind::payloadType() ignores it when sending.
        $refs->upsert($blob->id, (string) $channel->getKey(), 'file-3', null, 'sticker');
        $this->assertSame('sticker', $refs->find($blob->id, (string) $channel->getKey())?->provider_kind);
    }
}
