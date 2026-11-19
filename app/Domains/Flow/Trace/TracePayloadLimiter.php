<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

/**
 * Keeps a trace envelope storable: valid JSON of at most {@see self::MAX_ENVELOPE_BYTES}.
 *
 * The envelope is first made safe for a `jsonb` column — invalid UTF-8 replaced, NUL characters removed, values JSON
 * cannot hold dropped — and an error message is cut to {@see self::MAX_ERROR_BYTES}. When the encoded envelope is still
 * too large, the values inside `input` and `output` are replaced by `"[truncated, N bytes]"` (N: the encoded size of the
 * value replaced), the largest first, until it fits, and the envelope gets `truncated: true`. Leaves go first; if many
 * small leaves keep it too large, `input` and `output` themselves are replaced. The other fields are never truncated.
 */
final readonly class TracePayloadLimiter
{
    public const int MAX_ENVELOPE_BYTES = 1_048_576;

    public const int MAX_ERROR_BYTES = 8_192;

    /** Leaves smaller than this are not worth replacing by a marker of about the same size. */
    private const int MIN_LEAF_BYTES = 64;

    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** @var list<string> the parts of the envelope that may be truncated */
    private const array PAYLOAD_KEYS = ['input', 'output'];

    /**
     * @param  array<string, mixed>  $envelope
     *
     * @return array<string, mixed>
     */
    public function limit(array $envelope, int $maxBytes = self::MAX_ENVELOPE_BYTES): array
    {
        $envelope = $this->normalize($envelope);

        if (is_array($envelope['error'] ?? null) && is_string($envelope['error']['message'] ?? null)
            && mb_strlen($envelope['error']['message'], '8bit') > self::MAX_ERROR_BYTES) {
            $envelope['error']['message'] = mb_strcut($envelope['error']['message'], 0, self::MAX_ERROR_BYTES) . '…';
        }

        $size = self::size($envelope);

        if ($size <= $maxBytes) {
            return $envelope;
        }

        $envelope['truncated'] = true;
        $size += mb_strlen(',"truncated":true', '8bit');

        $leaves = [];

        foreach (self::PAYLOAD_KEYS as $key) {
            if (is_array($envelope[$key] ?? null)) {
                $this->collectLeaves($envelope[$key], [$key], $leaves);
            }
        }

        usort($leaves, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        foreach ($leaves as $leaf) {
            if ($size <= $maxBytes) {
                break;
            }

            $marker = self::marker($leaf['bytes']);
            self::replace($envelope, $leaf['path'], $marker);
            $size -= $leaf['bytes'] - self::size($marker);
        }

        $size = self::size($envelope);

        if ($size > $maxBytes) {
            $parts = [];

            foreach (self::PAYLOAD_KEYS as $key) {
                if (array_key_exists($key, $envelope)) {
                    $parts[$key] = self::size($envelope[$key]);
                }
            }

            arsort($parts);

            foreach ($parts as $key => $bytes) {
                if ($size <= $maxBytes) {
                    break;
                }

                $envelope[$key] = self::marker($bytes);
                $size           = self::size($envelope);
            }
        }

        return $envelope;
    }

    private static function withoutNul(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", '', $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $clean[is_string($key) ? str_replace("\0", '', $key) : $key] = self::withoutNul($item);
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $envelope
     * @param  list<array-key>  $path
     */
    private static function replace(array &$envelope, array $path, string $marker): void
    {
        $target = &$envelope;

        foreach ($path as $key) {
            $target = &$target[$key];
        }

        $target = $marker;
    }

    private static function marker(int $bytes): string
    {
        return sprintf('[truncated, %d bytes]', $bytes);
    }

    private static function size(mixed $value): int
    {
        $json = json_encode($value, self::JSON_FLAGS);

        return false === $json ? 0 : mb_strlen($json, '8bit');
    }

    /**
     * The envelope as it will be stored: decoded from its own lenient encoding.
     *
     * @param  array<string, mixed>  $envelope
     *
     * @return array<string, mixed>
     */
    private function normalize(array $envelope): array
    {
        $json    = json_encode(self::withoutNul($envelope), self::JSON_FLAGS);
        $decoded = false === $json ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<array-key>  $path
     * @param  list<array{path: list<array-key>, bytes: int}>  $leaves
     */
    private function collectLeaves(array $value, array $path, array &$leaves): void
    {
        foreach ($value as $key => $item) {
            $itemPath = [...$path, $key];

            if (is_array($item) && [] !== $item) {
                $this->collectLeaves($item, $itemPath, $leaves);

                continue;
            }

            $bytes = self::size($item);

            if ($bytes >= self::MIN_LEAF_BYTES) {
                $leaves[] = ['path' => $itemPath, 'bytes' => $bytes];
            }
        }
    }
}
