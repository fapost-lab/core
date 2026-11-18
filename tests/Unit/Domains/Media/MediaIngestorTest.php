<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Channels\Models\Channel;
use App\Domains\Media\Contracts\MediaChannelRefRepositoryInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Models\MediaChannelRef;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Registries\ChannelMediaDownloaderRegistry;
use App\Domains\Media\Services\MediaIngestor;
use Fapost\Foundation\Media\ChannelMediaDownloaderInterface;
use Fapost\Foundation\Media\DTO\DownloadResult;
use GuzzleHttp\Psr7\Utils;
use Mockery;
use Tests\TestCase;

final class MediaIngestorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_cached_ref_keeps_the_way_the_provider_carried_the_file_in(): void
    {
        $channel = (new Channel())->forceFill(['type' => 'telegram']);
        $channel->setAttribute('id', 'channel-1');

        $downloader = Mockery::mock(ChannelMediaDownloaderInterface::class);
        $downloader->shouldReceive('channelType')->andReturn('telegram');
        $downloader->shouldReceive('download')->once()->andReturn(
            new DownloadResult(Utils::streamFor('x'), 'audio/ogg', 1),
        );

        $media = (new MediaFile())->forceFill(['blob_id' => 'blob-1']);

        $uploader = Mockery::mock(MediaUploaderInterface::class);
        $uploader->shouldReceive('storeFromStream')->once()->andReturn($media);

        $refs = Mockery::mock(MediaChannelRefRepositoryInterface::class);
        $refs->shouldReceive('upsert')->once()
            ->with('blob-1', 'channel-1', 'voice-file-id', null, 'voice')
            ->andReturn(new MediaChannelRef());

        $ingestor = new MediaIngestor(new ChannelMediaDownloaderRegistry([$downloader]), $uploader, $refs);

        $this->assertSame($media, $ingestor->ingestFromChannel($channel, 'voice-file-id', providerKind: 'voice'));
    }
}
