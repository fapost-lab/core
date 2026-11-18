<?php

declare(strict_types=1);

namespace App\Domains\Flow\Expression;

/**
 * The `.length` pseudo-accessor shared by templates and conditions.
 *
 * `contact.photos.length` yields the element count of the list, `flow.name.length` the number of characters of the
 * text. A missing value (null) and any other scalar have length 0, so `{{x.length}}` always renders a number. A real
 * state key literally named `length` wins: the accessor applies only when that key holds nothing.
 */
final class PathLength
{
    public const string SUFFIX = '.length';

    /** The path of the value whose length is asked for, or null when `$path` is not a `.length` path. */
    public static function parentPath(string $path): ?string
    {
        if (! str_ends_with($path, self::SUFFIX)) {
            return null;
        }

        $parent = mb_substr($path, 0, -mb_strlen(self::SUFFIX));

        // A bare namespace (`contact.length`) is not a value that has a length.
        return str_contains($parent, '.') ? $parent : null;
    }

    public static function of(mixed $value): int
    {
        return match (true) {
            is_array($value)  => count($value),
            is_string($value) => mb_strlen($value),
            default           => 0,
        };
    }
}
