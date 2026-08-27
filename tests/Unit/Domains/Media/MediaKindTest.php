<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use Fapost\Foundation\Media\Enums\MediaKind;
use PHPUnit\Framework\TestCase;

final class MediaKindTest extends TestCase
{
    public function test_resolves_image_mime_types(): void
    {
        $this->assertSame(MediaKind::Image, MediaKind::fromMimeType('image/jpeg'));
        $this->assertSame(MediaKind::Image, MediaKind::fromMimeType('IMAGE/PNG'));
    }

    public function test_resolves_video_mime_types(): void
    {
        $this->assertSame(MediaKind::Video, MediaKind::fromMimeType('video/mp4'));
    }

    public function test_resolves_audio_mime_types(): void
    {
        $this->assertSame(MediaKind::Audio, MediaKind::fromMimeType('audio/ogg'));
    }

    public function test_unknown_mime_type_falls_back_to_document(): void
    {
        $this->assertSame(MediaKind::Document, MediaKind::fromMimeType('application/pdf'));
        $this->assertSame(MediaKind::Document, MediaKind::fromMimeType('foo/bar'));
    }

    public function test_empty_mime_type_falls_back_to_other(): void
    {
        $this->assertSame(MediaKind::Other, MediaKind::fromMimeType(''));
        $this->assertSame(MediaKind::Other, MediaKind::fromMimeType('   '));
    }
}
