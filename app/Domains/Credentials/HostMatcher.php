<?php

declare(strict_types=1);

namespace App\Domains\Credentials;

/**
 * Host patterns of a credential's allowed list: an exact host name (or IPv4 literal) or `*.domain`, which stands
 * for any subdomain of the domain at any depth but not for the domain itself.
 *
 * A host compares case-insensitively, as punycode, without a trailing dot. Stateless.
 */
final readonly class HostMatcher
{
    private const string WILDCARD = '*.';

    /**
     * Whether the text is a host name, an IPv4 literal or `*.domain` with at least two labels after the wildcard.
     * A scheme, port, path, space, IPv6 literal or a bare `*` is not.
     */
    public static function isValidPattern(string $pattern): bool
    {
        $pattern = self::normalize($pattern);

        if (str_starts_with($pattern, self::WILDCARD)) {
            $domain = mb_substr($pattern, 2, null, '8bit');

            return str_contains($domain, '.') && self::isHostName($domain);
        }

        return self::isHostName($pattern);
    }

    /**
     * The pattern lowercased, as punycode and without a trailing dot; a text that does not convert is returned
     * lowercased as it is (and fails {@see self::isValidPattern()}).
     */
    public static function normalize(string $pattern): string
    {
        $pattern  = mb_strtolower(mb_trim($pattern));
        $wildcard = str_starts_with($pattern, self::WILDCARD);
        $host     = $wildcard ? mb_substr($pattern, 2) : $pattern;
        $host     = mb_rtrim($host, '.');

        if (1 === preg_match('/[^\x00-\x7F]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && '' !== $ascii) {
                $host = $ascii;
            }
        }

        return $wildcard ? self::WILDCARD . $host : $host;
    }

    /**
     * Whether the host is covered by any of the patterns. An invalid pattern covers nothing.
     *
     * @param  list<string>  $patterns
     */
    public static function matches(string $host, array $patterns): bool
    {
        $host = self::normalize($host);

        if ('' === $host || str_starts_with($host, self::WILDCARD) || !self::isHostName($host)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (!self::isValidPattern($pattern)) {
                continue;
            }

            $pattern = self::normalize($pattern);

            if (str_starts_with($pattern, self::WILDCARD)) {
                $suffix = mb_substr($pattern, 1, null, '8bit');

                if (mb_strlen($host, '8bit') > mb_strlen($suffix, '8bit') && str_ends_with($host, $suffix)) {
                    return true;
                }

                continue;
            }

            if ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    private static function isHostName(string $host): bool
    {
        if ('' === $host || mb_strlen($host, '8bit') > 253) {
            return false;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if (1 !== preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        // An all-numeric last label is an address, and must be a whole IPv4 one (no `1.2.3`, no `999.1.1.1`).
        if (1 === preg_match('/^[0-9]+$/', $labels[array_key_last($labels)])) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        }

        return true;
    }
}
