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
}
