<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels\Telegram\Media;

use App\Domains\Channels\Telegram\Media\TelegramMediaDownloader;
use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Media\Exceptions\MediaIngestException;
use Fapost\Foundation\Channel\ChannelInterface;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class TelegramMediaDownloaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_downloads_file_via_get_file_then_streams_bytes(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile'                   => Http::response([
                'ok'     => true,
                'result' => [
                    'file_path' => 'documents/report.pdf',
                    'file_size' => 6,
                ],
            ]),
            'api.telegram.org/file/bot*/documents/report.pdf' => Http::response('binary'),
        ]);

        $downloader = new TelegramMediaDownloader(new TelegramBotApiClientFactory());
        $result     = $downloader->download($this->makeChannel(), 'file-id');

        $this->assertSame('application/pdf', $result->mimeType);
        $this->assertSame(6, $result->size);
        $this->assertSame('report.pdf', $result->originalFilename);
        $this->assertNull($result->expiresAt);
    }

    public function test_throws_media_ingest_exception_when_get_file_fails(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response(['ok' => false, 'description' => 'boom'], 400),
        ]);

        $downloader = new TelegramMediaDownloader(new TelegramBotApiClientFactory());

        $this->expectException(MediaIngestException::class);
        $downloader->download($this->makeChannel(), 'file-id');
    }

    public function test_throws_media_ingest_exception_when_file_path_missing(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getFile' => Http::response([
                'ok'     => true,
                'result' => ['file_size' => 0],
            ]),
        ]);

        $downloader = new TelegramMediaDownloader(new TelegramBotApiClientFactory());

        $this->expectException(MediaIngestException::class);
        $downloader->download($this->makeChannel(), 'file-id');
    }

    private function makeChannel(): ChannelInterface
    {
        $channel = Mockery::mock(ChannelInterface::class);
        $channel->shouldReceive('getConfig')->andReturn(['token' => 'bot-token']);

        return $channel;
    }
}
