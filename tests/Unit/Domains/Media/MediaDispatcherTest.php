<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Media\Contracts\MediaChannelRefRepositoryInterface;
use App\Domains\Media\Exceptions\ChannelMediaUploaderNotRegisteredException;
use App\Domains\Media\Exceptions\MediaDeletedException;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaChannelRef;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Registries\ChannelMediaLinkPolicyRegistry;
use App\Domains\Media\Registries\ChannelMediaUploaderRegistry;
use App\Domains\Media\Services\MediaDispatcher;
use App\Domains\Media\Services\PublicMediaExtension;
use App\Domains\Media\Services\PublicMediaLinkIssuer;
use App\Domains\Media\Services\PublicMediaLinkSigner;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Media\ChannelMediaLinkPolicyInterface;
use Fapost\Foundation\Media\ChannelMediaUploaderInterface;
use Fapost\Foundation\Media\DTO\UploadResult;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Media\MediaBlobReadInterface;
use Fapost\Foundation\Media\ReportsProviderKind;
use Mockery;
use Tests\TestCase;

final class MediaDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_cached_provider_file_id_when_ref_is_fresh(): void
    {
        $blob    = $this->makeBlob('blob-1');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-1');

        $ref = new MediaChannelRef();
        $ref->forceFill([
            'blob_id'          => 'blob-1',
            'channel_id'       => 'channel-1',
            'provider_file_id' => 'provider-x',
            'provider_kind'    => 'photo',
            'expires_at'       => null,
            'uploaded_at'      => CarbonImmutable::now(),
        ]);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->with('blob-1', 'channel-1')->andReturn($ref);
        $refs->shouldNotReceive('upsert');

        $registry = new ChannelMediaUploaderRegistry();

        $dispatcher = $this->makeDispatcher($refs, $registry);

        $result = $dispatcher->ensureUploadedToChannel($media, $channel);
        $this->assertSame('provider-x', $result->providerFileId);
        $this->assertFalse($result->alreadyDelivered);
    }

    public function test_a_ref_without_a_stored_kind_is_not_reused_for_an_uploader_that_reports_one_and_the_new_upload_stores_the_kind(): void
    {
        $blob    = $this->makeBlob('blob-legacy');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-legacy', ChannelTypeEnum::Telegram);

        $legacy = new MediaChannelRef();
        $legacy->forceFill([
            'blob_id'          => 'blob-legacy',
            'channel_id'       => 'channel-legacy',
            'provider_file_id' => 'old-voice-id',
            'provider_kind'    => null,
            'expires_at'       => null,
        ]);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->andReturn($legacy);
        $refs->shouldReceive('upsert')->once()
            ->with('blob-legacy', 'channel-legacy', 'new-audio-id', null, 'audio')
            ->andReturn(new MediaChannelRef());

        $uploader = Mockery::mock(ChannelMediaUploaderInterface::class, ReportsProviderKind::class);
        $uploader->shouldReceive('channelType')->andReturn('telegram');
        $uploader->shouldReceive('upload')->once()->andReturn(new UploadResult('new-audio-id', null, '5', 'audio'));

        $dispatcher = $this->makeDispatcher($refs, new ChannelMediaUploaderRegistry([$uploader]));

        $result = $dispatcher->ensureUploadedToChannel($media, $channel);

        $this->assertSame('new-audio-id', $result->providerFileId);
        $this->assertSame('audio', $result->providerKind);
        $this->assertTrue($result->alreadyDelivered);
    }

    public function test_an_unknown_kind_from_a_reporting_uploader_is_stored_and_the_ref_is_reused(): void
    {
        $blob    = $this->makeBlob('blob-unk');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-unk', ChannelTypeEnum::Telegram);

        $cached = new MediaChannelRef();
        $cached->forceFill(['blob_id' => 'blob-unk', 'channel_id' => 'channel-unk', 'provider_file_id' => 'x-id', 'provider_kind' => 'sticker', 'expires_at' => null]);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->twice()->andReturn(null, $cached);
        $refs->shouldReceive('upsert')->once()->with('blob-unk', 'channel-unk', 'x-id', null, 'sticker')->andReturn(new MediaChannelRef());

        $uploader = Mockery::mock(ChannelMediaUploaderInterface::class, ReportsProviderKind::class);
        $uploader->shouldReceive('channelType')->andReturn('telegram');
        $uploader->shouldReceive('upload')->once()->andReturn(new UploadResult('x-id', null, '3', 'sticker'));

        $dispatcher = $this->makeDispatcher($refs, new ChannelMediaUploaderRegistry([$uploader]));

        $dispatcher->ensureUploadedToChannel($media, $channel);
        $second = $dispatcher->ensureUploadedToChannel($media, $channel);

        $this->assertSame('x-id', $second->providerFileId);
    }

    public function test_a_ref_without_a_kind_is_reused_for_an_uploader_that_does_not_report_one(): void
    {
        $blob    = $this->makeBlob('blob-v');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-v', 'viber');

        $ref = new MediaChannelRef();
        $ref->forceFill(['blob_id' => 'blob-v', 'channel_id' => 'channel-v', 'provider_file_id' => 'v-id', 'expires_at' => null]);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->andReturn($ref);
        $refs->shouldNotReceive('upsert');

        $uploader = Mockery::mock(ChannelMediaUploaderInterface::class);
        $uploader->shouldReceive('channelType')->andReturn('viber');
        $uploader->shouldNotReceive('upload');

        $result = $this->makeDispatcher($refs, new ChannelMediaUploaderRegistry([$uploader]))->ensureUploadedToChannel($media, $channel);

        $this->assertSame('v-id', $result->providerFileId);
    }

    public function test_uploads_when_no_ref_exists_and_caches_result(): void
    {
        $blob    = $this->makeBlob('blob-2');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-2', ChannelTypeEnum::Telegram);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->with('blob-2', 'channel-2')->andReturnNull();
        $refs->shouldReceive('upsert')->once()->with('blob-2', 'channel-2', 'provider-y', null, null)->andReturn(
            new MediaChannelRef()
        );

        $uploader = Mockery::mock(ChannelMediaUploaderInterface::class);
        $uploader->shouldReceive('channelType')->andReturn('telegram');
        $uploader->shouldReceive('upload')
            ->once()
            ->with(
                Mockery::type(MediaBlobReadInterface::class),
                Mockery::type(ChannelInterface::class),
                Mockery::any(),
            )
            ->andReturn(new UploadResult('provider-y'));

        $registry = new ChannelMediaUploaderRegistry([$uploader]);

        $dispatcher = $this->makeDispatcher($refs, $registry);

        $result = $dispatcher->ensureUploadedToChannel($media, $channel);
        $this->assertSame('provider-y', $result->providerFileId);
        $this->assertFalse($result->alreadyDelivered);
        $this->assertNull($result->deliveredMessageId);
    }

    public function test_re_uploads_when_ref_is_expired(): void
    {
        CarbonImmutable::setTestNow('2026-04-27 12:00:00');

        $blob    = $this->makeBlob('blob-3');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-3', 'viber');

        $ref = new MediaChannelRef();
        $ref->forceFill([
            'blob_id'          => 'blob-3',
            'channel_id'       => 'channel-3',
            'provider_file_id' => 'provider-old',
            'expires_at'       => CarbonImmutable::parse('2026-04-26 11:00:00'),
            'uploaded_at'      => CarbonImmutable::parse('2026-03-26 11:00:00'),
        ]);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->andReturn($ref);
        $refs->shouldReceive('upsert')->once()->with('blob-3', 'channel-3', 'provider-new', null, null)->andReturn(
            new MediaChannelRef()
        );

        $uploader = Mockery::mock(ChannelMediaUploaderInterface::class);
        $uploader->shouldReceive('channelType')->andReturn('viber');
        $uploader->shouldReceive('upload')->once()->andReturn(new UploadResult('provider-new'));

        $registry = new ChannelMediaUploaderRegistry([$uploader]);

        $dispatcher = $this->makeDispatcher($refs, $registry);

        $result = $dispatcher->ensureUploadedToChannel($media, $channel);
        $this->assertSame('provider-new', $result->providerFileId);
    }

    public function test_marks_dispatch_as_already_delivered_when_uploader_returns_message_id(): void
    {
        $blob    = $this->makeBlob('blob-upload-as-send');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-uas', ChannelTypeEnum::Telegram);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->andReturnNull();
        $refs->shouldReceive('upsert')->once()->andReturn(new MediaChannelRef());

        $uploader = Mockery::mock(ChannelMediaUploaderInterface::class);
        $uploader->shouldReceive('channelType')->andReturn('telegram');
        $uploader->shouldReceive('upload')->once()->andReturn(
            new UploadResult(
                providerFileId: 'tg-file-id',
                expiresAt: null,
                deliveredMessageId: '12345',
            )
        );

        $registry = new ChannelMediaUploaderRegistry([$uploader]);

        $dispatcher = $this->makeDispatcher($refs, $registry);

        $result = $dispatcher->ensureUploadedToChannel($media, $channel);

        $this->assertSame('tg-file-id', $result->providerFileId);
        $this->assertTrue($result->alreadyDelivered);
        $this->assertSame('12345', $result->deliveredMessageId);
    }

    public function test_stores_the_kind_the_provider_reported_and_returns_it_on_a_cache_hit(): void
    {
        $blob    = $this->makeBlob('blob-kind');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-kind', ChannelTypeEnum::Telegram);

        $cached = new MediaChannelRef();
        $cached->forceFill([
            'blob_id'          => 'blob-kind',
            'channel_id'       => 'channel-kind',
            'provider_file_id' => 'tg-doc',
            'provider_kind'    => 'document',
        ]);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->twice()->andReturn(null, $cached);
        $refs->shouldReceive('upsert')->once()->with('blob-kind', 'channel-kind', 'tg-doc', null, 'document')->andReturn(new MediaChannelRef());

        $uploader = Mockery::mock(ChannelMediaUploaderInterface::class);
        $uploader->shouldReceive('channelType')->andReturn('telegram');
        $uploader->shouldReceive('upload')->once()->andReturn(new UploadResult('tg-doc', null, '9', 'document'));

        $dispatcher = $this->makeDispatcher($refs, new ChannelMediaUploaderRegistry([$uploader]));

        $first = $dispatcher->ensureUploadedToChannel($media, $channel);
        $this->assertSame('document', $first->providerKind);

        $second = $dispatcher->ensureUploadedToChannel($media, $channel);
        $this->assertSame('tg-doc', $second->providerFileId);
        $this->assertSame('document', $second->providerKind);
    }

    public function test_throws_when_no_uploader_registered_for_channel_type(): void
    {
        $blob    = $this->makeBlob('blob-4');
        $media   = $this->makeMedia($blob);
        $channel = $this->makeChannel('channel-4', 'viber');

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->andReturnNull();

        $registry = new ChannelMediaUploaderRegistry();

        $dispatcher = $this->makeDispatcher($refs, $registry);

        $this->expectException(ChannelMediaUploaderNotRegisteredException::class);

        $dispatcher->ensureUploadedToChannel($media, $channel);
    }

    public function test_rejects_soft_deleted_media(): void
    {
        $blob              = $this->makeBlob('blob-5');
        $media             = $this->makeMedia($blob);
        $media->deleted_at = CarbonImmutable::now();

        $channel = $this->makeChannel('channel-5');

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldNotReceive('find');

        $dispatcher = $this->makeDispatcher($refs, new ChannelMediaUploaderRegistry());

        $this->expectException(MediaDeletedException::class);

        $dispatcher->ensureUploadedToChannel($media, $channel);
    }

    public function test_a_link_policy_channel_gets_a_public_link_and_no_ref_is_written(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 12:00:00');

        $media   = $this->makeMedia($this->makeBlob('blob-link'));
        $channel = $this->makeChannel('channel-link', 'viber');

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldNotReceive('find');
        $refs->shouldNotReceive('upsert');

        $dispatcher = $this->makeDispatcher($refs, new ChannelMediaUploaderRegistry(), $this->policyRegistry('viber', 7200));

        $result = $dispatcher->ensureUploadedToChannel($media, $channel);

        $this->assertNull($result->providerFileId);
        $this->assertFalse($result->alreadyDelivered);
        $this->assertNotNull($result->publicLink);
        $this->assertStringContainsString('/media/public/media-blob-link/', $result->publicLink->url);
        $this->assertStringEndsWith('/photo.jpeg', $result->publicLink->url);
        $this->assertSame('image/jpeg', $result->publicLink->mimeType);
        $this->assertSame(100, $result->publicLink->size);
        $this->assertSame(MediaKind::Image, $result->publicLink->kind);
        $this->assertSame(CarbonImmutable::now()->getTimestamp() + 7200, $result->publicLink->expiresAt->getTimestamp());
    }

    public function test_an_uploader_wins_over_a_link_policy_of_the_same_type(): void
    {
        $media   = $this->makeMedia($this->makeBlob('blob-both'));
        $channel = $this->makeChannel('channel-both', ChannelTypeEnum::Telegram);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('find')->andReturnNull();
        $refs->shouldReceive('upsert')->once()->andReturn(new MediaChannelRef());

        $uploader = Mockery::mock(ChannelMediaUploaderInterface::class);
        $uploader->shouldReceive('channelType')->andReturn('telegram');
        $uploader->shouldReceive('upload')->once()->andReturn(new UploadResult('tg-id'));

        $dispatcher = $this->makeDispatcher(
            $refs,
            new ChannelMediaUploaderRegistry([$uploader]),
            $this->policyRegistry('telegram'),
        );

        $result = $dispatcher->ensureUploadedToChannel($media, $channel);

        $this->assertSame('tg-id', $result->providerFileId);
        $this->assertNull($result->publicLink);
    }

    public function test_a_soft_deleted_file_gets_no_link_even_for_a_link_policy_channel(): void
    {
        $media             = $this->makeMedia($this->makeBlob('blob-link-del'));
        $media->deleted_at = CarbonImmutable::now();

        $dispatcher = $this->makeDispatcher(
            Mockery::mock(MediaChannelRefRepositoryInterface::class),
            new ChannelMediaUploaderRegistry(),
            $this->policyRegistry('viber'),
        );

        $this->expectException(MediaDeletedException::class);

        $dispatcher->ensureUploadedToChannel($media, $this->makeChannel('channel-link-del', 'viber'));
    }

    private function makeDispatcher(
        MediaChannelRefRepositoryInterface $refs,
        ChannelMediaUploaderRegistry $uploaders,
        ?ChannelMediaLinkPolicyRegistry $policies = null,
    ): MediaDispatcher {
        $tenantContext = new TenantContext();
        $tenantContext->set(new RuntimeTenant(id: 'tenant-1', schemaName: 'main'));

        return new MediaDispatcher(
            $refs,
            $uploaders,
            $policies ?? new ChannelMediaLinkPolicyRegistry(),
            new PublicMediaLinkIssuer(
                new PublicMediaLinkSigner('base64:' . base64_encode(str_repeat('k', 32))),
                new PublicMediaExtension(),
            ),
            $tenantContext,
        );
    }

    private function policyRegistry(string $type, ?int $ttl = null): ChannelMediaLinkPolicyRegistry
    {
        $policy = Mockery::mock(ChannelMediaLinkPolicyInterface::class);
        $policy->shouldReceive('channelType')->andReturn($type);
        $policy->shouldReceive('linkTtlSeconds')->andReturn($ttl);

        return new ChannelMediaLinkPolicyRegistry([$policy]);
    }

    private function makeBlob(string $id): MediaBlob
    {
        $blob = new MediaBlob();
        $blob->forceFill([
            'id'           => $id,
            'tenant_id'    => 'tenant-1',
            'content_hash' => str_repeat('a', 64),
            'storage_path' => 'tenants/tenant-1/media/' . $id,
            'storage_disk' => 'local',
            'size'         => 100,
            'mime_type'    => 'image/jpeg',
        ]);
        $blob->exists = true;

        return $blob;
    }

    private function makeMedia(MediaBlob $blob): MediaFile
    {
        $media = new MediaFile();
        $media->forceFill([
            'id'        => 'media-' . $blob->id,
            'tenant_id' => 'tenant-1',
            'blob_id'   => $blob->id,
            'name'      => 'photo.jpg',
            'kind'      => 'image',
            'metadata'  => [],
            'source'    => 'upload',
        ]);
        $media->exists = true;
        $media->setRelation('blob', $blob);

        return $media;
    }

    private function makeChannel(string $id, ChannelTypeEnum|string $type = ChannelTypeEnum::Telegram): Channel
    {
        $channel = new Channel();
        $channel->forceFill([
            'id'                  => $id,
            'tenant_id'           => 'tenant-1',
            'assistant_id'        => 'assistant-1',
            'type'                => $type,
            'token'               => 'token',
            'secret_token'        => 'secret',
            'webhook_public_hash' => 'hash',
            'config'              => [],
            'is_active'           => true,
        ]);
        $channel->exists = true;

        return $channel;
    }
}
