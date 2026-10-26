<?php

declare(strict_types=1);

namespace App\Domains\Health\Support;

/**
 * Cuts every URL in a text down to scheme, host, port and path.
 *
 * A gateway address may be configured with a user name, a password or a token in its query, and a probe's reason or
 * details repeat the address it asked. Whatever shows the health of the installation outside Core's own code — the
 * console page, the Foundation health contract — passes text through here first, so the rule exists once.
 */
final class UrlScrubber
{
    public static function scrub(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }

        return preg_replace_callback(
            '~https?://[^\s"\'<>]+~i',
            static function (array $match): string {
                $parts = parse_url(mb_rtrim($match[0], '.,;:)'));

                if (false === $parts || ! isset($parts['scheme'], $parts['host'])) {
                    return $match[0];
                }

                $trailing = mb_substr($match[0], mb_strlen(mb_rtrim($match[0], '.,;:)')));

                return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '') . $trailing;
            },
            $text,
        );
    }
}
