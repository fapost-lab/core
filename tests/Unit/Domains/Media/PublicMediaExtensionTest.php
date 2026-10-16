<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Media\Services\PublicMediaExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicMediaExtensionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function mimeTypes(): array
    {
        return [
            'jpeg is jpeg, not jpg' => ['image/jpeg', 'jpeg'],
            'png'                   => ['image/png', 'png'],
            'gif'                   => ['image/gif', 'gif'],
            'mp4'                   => ['video/mp4', 'mp4'],
            'pdf'                   => ['application/pdf', 'pdf'],
            'upper case'            => ['IMAGE/PNG', 'png'],
            'unknown'               => ['application/x-unknown', 'bin'],
            'empty'                 => ['', 'bin'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fileNames(): array
    {
        return [
            'plain'           => ['holiday.jpg', 'holiday'],
            'cyrillic'        => ['Привет мир.pdf', 'privet-mir'],
            'dots and spaces' => ['my.big photo.png', 'my-big-photo'],
            'no extension'    => ['report', 'report'],
            'only symbols'    => ['***.png', 'file'],
            'empty'           => ['', 'file'],
        ];
    }

    #[DataProvider('mimeTypes')]
    public function test_extension_follows_the_mime_type(string $mime, string $expected): void
    {
        $this->assertSame($expected, (new PublicMediaExtension())->extensionFor($mime));
    }

    #[DataProvider('fileNames')]
    public function test_slug_is_ascii_and_never_empty(string $name, string $expected): void
    {
        $this->assertSame($expected, (new PublicMediaExtension())->slugOf($name));
    }

    public function test_slug_is_cut_at_64_characters_without_a_trailing_dash(): void
    {
        $slug = (new PublicMediaExtension())->slugOf(str_repeat('a', 63) . ' ' . str_repeat('b', 10) . '.png');

        $this->assertSame(str_repeat('a', 63), $slug);
    }

    public function test_segment_fits_the_route_constraint(): void
    {
        $segment = (new PublicMediaExtension())->segmentFor(str_repeat('ж', 200) . '.jpg', 'image/jpeg');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,80}$/', $segment);
        $this->assertStringEndsWith('.jpeg', $segment);
    }
}
