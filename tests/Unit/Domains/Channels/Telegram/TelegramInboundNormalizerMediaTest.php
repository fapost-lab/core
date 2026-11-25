<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\TelegramInboundNormalizer;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Media\Enums\MediaKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TelegramInboundNormalizerMediaTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function telegram_attachments(): array
    {
        return [
            'photo'    => ['photo', [['file_id' => 'p1', 'file_size' => 100]]],
            'document' => ['document', ['file_id' => 'd1', 'mime_type' => 'audio/x-wav']],
            'video'    => ['video', ['file_id' => 'v1', 'mime_type' => 'video/mp4']],
            'voice'    => ['voice', ['file_id' => 'vo1', 'mime_type' => 'audio/ogg']],
            'audio'    => ['audio', ['file_id' => 'a1', 'mime_type' => 'audio/mpeg']],
        ];
    }
    public function test_extracts_largest_photo_variant(): void
    {
        $update = [
            'update_id' => 1,
            'message'   => [
                'message_id' => 10,
                'chat'       => ['id' => 123],
                'from'       => ['id' => 456],
                'caption'    => 'A nice picture',
                'photo'      => [
                    ['file_id' => 'small', 'file_size' => 50, 'width' => 90, 'height' => 90],
                    ['file_id' => 'large', 'file_size' => 500, 'width' => 1280, 'height' => 1280],
                ],
            ],
        ];

        $message = (new TelegramInboundNormalizer())->normalize($update);

        $this->assertSame(IncomingMessageType::Photo, $message->type);
        $this->assertCount(1, $message->media);
        $this->assertSame('large', $message->media[0]->providerFileId);
        $this->assertSame(MediaKind::Image, $message->media[0]->kind);
        $this->assertSame(500, $message->media[0]->size);
        $this->assertSame('A nice picture', $message->text);
    }

    public function test_extracts_document(): void
    {
        $update = [
            'update_id' => 2,
            'message'   => [
                'message_id' => 11,
                'chat'       => ['id' => 1],
                'from'       => ['id' => 2],
                'document'   => [
                    'file_id'   => 'doc-id',
                    'file_name' => 'report.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => 2048,
                ],
            ],
        ];

        $message = (new TelegramInboundNormalizer())->normalize($update);

        $this->assertSame(IncomingMessageType::Document, $message->type);
        $this->assertCount(1, $message->media);
        $this->assertSame('doc-id', $message->media[0]->providerFileId);
        $this->assertSame(MediaKind::Document, $message->media[0]->kind);
        $this->assertSame('application/pdf', $message->media[0]->mimeType);
        $this->assertSame('report.pdf', $message->media[0]->fileName);
    }

    public function test_extracts_voice_as_audio_kind(): void
    {
        $update = [
            'update_id' => 3,
            'message'   => [
                'message_id' => 12,
                'chat'       => ['id' => 1],
                'from'       => ['id' => 2],
                'voice'      => [
                    'file_id'   => 'voice-id',
                    'mime_type' => 'audio/ogg',
                    'duration'  => 5,
                ],
            ],
        ];

        $message = (new TelegramInboundNormalizer())->normalize($update);

        $this->assertSame(IncomingMessageType::Voice, $message->type);
        $this->assertCount(1, $message->media);
        $this->assertSame(MediaKind::Audio, $message->media[0]->kind);
    }

    public function test_text_only_message_yields_empty_media_array(): void
    {
        $update = [
            'update_id' => 4,
            'message'   => [
                'message_id' => 13,
                'chat'       => ['id' => 1],
                'from'       => ['id' => 2],
                'text'       => 'Hello',
            ],
        ];

        $message = (new TelegramInboundNormalizer())->normalize($update);

        $this->assertSame(IncomingMessageType::Text, $message->type);
        $this->assertSame([], $message->media);
        $this->assertSame('Hello', $message->text);
    }

    public function test_carries_media_group_id_in_payload(): void
    {
        $update = [
            'update_id' => 5,
            'message'   => [
                'message_id'     => 14,
                'chat'           => ['id' => 1],
                'from'           => ['id' => 2],
                'media_group_id' => 'group-1',
                'photo'          => [['file_id' => 'p1', 'file_size' => 100]],
            ],
        ];

        $message = (new TelegramInboundNormalizer())->normalize($update);

        $this->assertSame('group-1', $message->payload['media_group_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    #[DataProvider('telegram_attachments')]
    public function test_each_file_carries_the_telegram_field_it_came_in_as_its_provider_kind(
        string $field,
        array $attachment,
    ): void {
        $update = [
            'update_id' => 6,
            'message'   => [
                'message_id' => 15,
                'chat'       => ['id' => 1],
                'from'       => ['id' => 2],
                $field       => $attachment,
            ],
        ];

        $message = (new TelegramInboundNormalizer())->normalize($update);

        $this->assertCount(1, $message->media);
        $this->assertSame($field, $message->media[0]->providerKind);
    }
}
