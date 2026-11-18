<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Media\Services\DeliveryKind;
use Fapost\Foundation\Media\Enums\MediaKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryKindTest extends TestCase
{
    /**
     * @return array<string, array{string, MediaKind}>
     */
    public static function mimeTypes(): array
    {
        return [
            'jpeg'    => ['image/jpeg', MediaKind::Image],
            'png'     => ['IMAGE/PNG', MediaKind::Image],
            'heic'    => ['image/heic', MediaKind::Document],
            'avif'    => ['image/avif', MediaKind::Document],
            'tiff'    => ['image/tiff', MediaKind::Document],
            'mp4'     => ['video/mp4', MediaKind::Video],
            'mkv'     => ['video/x-matroska', MediaKind::Document],
            'mp3'     => ['audio/mpeg', MediaKind::Audio],
            'm4a'     => ['audio/x-m4a', MediaKind::Audio],
            'wav'     => ['audio/wav', MediaKind::Audio],
            'flac'    => ['audio/flac', MediaKind::Document],
            'pdf'     => ['application/pdf', MediaKind::Document],
            'no mime' => ['', MediaKind::Other],
        ];
    }

    #[DataProvider('mimeTypes')]
    public function test_a_format_messengers_do_not_play_inline_travels_as_a_document(string $mime, MediaKind $expected): void
    {
        $this->assertSame($expected, DeliveryKind::of($mime));
    }

    public function test_the_stored_provider_kind_decides_the_message_type_over_the_format(): void
    {
        $this->assertSame('document', DeliveryKind::payloadType('document', 'audio/wav'));
        $this->assertSame('voice', DeliveryKind::payloadType('voice', 'audio/ogg'));
    }

    public function test_an_inbound_voice_note_and_a_jpeg_sent_as_a_document_go_back_the_way_they_came(): void
    {
        $this->assertSame('voice', DeliveryKind::payloadType('voice', 'audio/ogg'));
        $this->assertSame('document', DeliveryKind::payloadType('document', 'image/jpeg'));
    }

    public function test_without_a_usable_stored_kind_the_format_decides(): void
    {
        $this->assertSame('audio', DeliveryKind::payloadType(null, 'audio/mpeg'));
        $this->assertSame('photo', DeliveryKind::payloadType(null, 'image/jpeg'));
        $this->assertSame('document', DeliveryKind::payloadType(null, 'image/heic'));
        $this->assertSame('video', DeliveryKind::payloadType('nonsense', 'video/mp4'));
    }
}
