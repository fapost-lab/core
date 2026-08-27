<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels\Telegram\Media;

use App\Domains\Channels\Telegram\Media\TelegramMediaUploader;
use App\Domains\Channels\Telegram\TelegramBotApiClientFactory;
use App\Domains\Media\Exceptions\MediaUploadFailedException;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Foundation\Media\DTO\UploadContext;
use Fapost\Foundation\Media\MediaBlobReadInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class TelegramMediaUploaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_uploads_image_via_send_photo_multipart_and_extracts_largest_file_id(): void
    {
        Http::fake([
            'api.telegram.org/*sendPhoto' => Http::response([
                'ok'     => true,
                'result' => [
                    'message_id' => 777,
                    'photo'      => [
                        ['file_id' => 'small'],
                        ['file_id' => 'large'],
                    ],
                ],
            ]),
        ]);

        $uploader = new TelegramMediaUploader(new TelegramBotApiClientFactory());
        $result   = $uploader->upload(
            $this->makeBlob('image/jpeg'),
            $this->makeChannel(),
            new UploadContext('chat-99', 'A caption'),
        );

        $this->assertSame('large', $result->providerFileId);
        $this->assertSame('777', $result->deliveredMessageId);
        $this->assertNull($result->expiresAt);

        Http::assertSent(static fn($request): bool => str_contains($request->url(), '/sendPhoto'));
    }

    public function test_uploads_document_when_kind_is_other(): void
    {
        Http::fake([
            'api.telegram.org/*sendDocument' => Http::response([
                'ok'     => true,
                'result' => [
                    'message_id' => 11,
                    'document'   => ['file_id' => 'doc-file-id'],
                ],
            ]),
        ]);

        $uploader = new TelegramMediaUploader(new TelegramBotApiClientFactory());
        $result   = $uploader->upload(
            $this->makeBlob('application/pdf'),
            $this->makeChannel(),
            new UploadContext('chat-1'),
        );

        $this->assertSame('doc-file-id', $result->providerFileId);
    }

    public function test_throws_when_chat_id_is_missing(): void
    {
        Http::fake();

        $uploader = new TelegramMediaUploader(new TelegramBotApiClientFactory());

        $this->expectException(MediaUploadFailedException::class);

        $uploader->upload(
            $this->makeBlob('image/jpeg'),
            $this->makeChannel(),
            new UploadContext(targetChatId: null),
        );

        Http::assertNothingSent();
    }

    public function test_channel_type_returns_telegram(): void
    {
        $this->assertSame('telegram', (new TelegramMediaUploader(new TelegramBotApiClientFactory()))->channelType());
    }

    private function makeBlob(string $mimeType): MediaBlobReadInterface
    {
        $blob = Mockery::mock(MediaBlobReadInterface::class);
        $blob->shouldReceive('getId')->andReturn('blob-1');
        $blob->shouldReceive('getMimeType')->andReturn($mimeType);
        $blob->shouldReceive('getSize')->andReturn(100);
        $blob->shouldReceive('getContentHash')->andReturn(str_repeat('a', 64));
        $blob->shouldReceive('openStream')->andReturnUsing(static fn() => Utils::streamFor('binary-bytes'));

        return $blob;
    }

    private function makeChannel(string $token = 'bot-token'): ChannelInterface
    {
        $channel = Mockery::mock(ChannelInterface::class);
        $channel->shouldReceive('getId')->andReturn('channel-1');
        $channel->shouldReceive('getType')->andReturn('telegram');
        $channel->shouldReceive('getConfig')->andReturn(['token' => $token]);

        return $channel;
    }
}
