<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use Illuminate\Support\Str;

/**
 * The last segment of a public media link: `{slug}.{extension}`.
 *
 * Providers that fetch a file by URL (Viber) decide by the extension in the path, so it is the canonical one for
 * the MIME type, in the spelling they accept (`jpeg`, not `jpg`). The slug is the file's name reduced to ASCII; the
 * whole segment is covered by the signature, so neither part can be swapped.
 */
final class PublicMediaExtension
{
    private const int SLUG_MAX_LENGTH = 64;

    private const string FALLBACK_EXTENSION = 'bin';

    /** @var array<string, string> */
    private const array EXTENSIONS = [
        'image/jpeg'                                                                => 'jpeg',
        'image/png'                                                                 => 'png',
        'image/gif'                                                                 => 'gif',
        'image/webp'                                                                => 'webp',
        'image/heic'                                                                => 'heic',
        'image/heif'                                                                => 'heif',
        'image/avif'                                                                => 'avif',
        'image/bmp'                                                                 => 'bmp',
        'image/x-ms-bmp'                                                            => 'bmp',
        'image/tiff'                                                                => 'tiff',
        'video/mp4'                                                                 => 'mp4',
        'video/quicktime'                                                           => 'mov',
        'video/webm'                                                                => 'webm',
        'video/x-matroska'                                                          => 'mkv',
        'video/x-msvideo'                                                           => 'avi',
        'video/3gpp'                                                                => '3gp',
        'audio/mpeg'                                                                => 'mp3',
        'audio/ogg'                                                                 => 'ogg',
        'audio/wav'                                                                 => 'wav',
        'audio/x-wav'                                                               => 'wav',
        'audio/mp4'                                                                 => 'm4a',
        'audio/x-m4a'                                                               => 'm4a',
        'audio/aac'                                                                 => 'aac',
        'audio/x-aac'                                                               => 'aac',
        'audio/flac'                                                                => 'flac',
        'audio/x-flac'                                                              => 'flac',
        'application/pdf'                                                           => 'pdf',
        'application/zip'                                                           => 'zip',
        'application/json'                                                          => 'json',
        'application/msword'                                                        => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.ms-excel'                                                  => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.ms-powerpoint'                                             => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text'                                   => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet'                            => 'ods',
        'application/vnd.oasis.opendocument.presentation'                           => 'odp',
        'application/rtf'                                                           => 'rtf',
        'text/rtf'                                                                  => 'rtf',
        'application/x-7z-compressed'                                               => '7z',
        'application/vnd.rar'                                                       => 'rar',
        'application/x-rar'                                                         => 'rar',
        'application/x-rar-compressed'                                              => 'rar',
        'application/gzip'                                                          => 'gz',
        'application/x-gzip'                                                        => 'gz',
        'text/plain'                                                                => 'txt',
        'text/csv'                                                                  => 'csv',
    ];

    public function extensionFor(string $mimeType): string
    {
        return self::EXTENSIONS[mb_strtolower(mb_trim($mimeType))] ?? self::FALLBACK_EXTENSION;
    }

    /**
     * ASCII slug of a file name without its extension, at most 64 characters; `file` when nothing is left.
     */
    public function slugOf(string $fileName): string
    {
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $slug = mb_rtrim(mb_substr(Str::slug(str_replace('.', ' ', $base)), 0, self::SLUG_MAX_LENGTH), '-');

        return '' === $slug ? 'file' : $slug;
    }

    /**
     * The file name a provider sees: the slug with the canonical extension.
     */
    public function segmentFor(string $fileName, string $mimeType): string
    {
        return $this->slugOf($fileName) . '.' . $this->extensionFor($mimeType);
    }
}
