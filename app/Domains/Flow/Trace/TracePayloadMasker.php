<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

use App\Domains\Flow\Support\SecretRedactor;

/**
 * Masks what must not reach a node trace, before it is truncated and written.
 *
 * Three passes over every value, at any depth:
 *
 * - the value of a key with a sensitive name ({@see self::SENSITIVE_KEYS}) becomes {@see self::MASK}. The name matches
 *   case-insensitively, whole or as the suffix after `.`, `-` or `_` (`headers.Authorization`, `auth.password`,
 *   `x-telegram-bot-api-secret-token`). A `query.<name>` key — the http transport's query parameter — is judged as that
 *   parameter of a URL would be, so `query.key` is masked like `?key=`;
 * - in every string, each http(s) URL keeps its shape but loses the password of its `user:pass@` part and the values
 *   of its query parameters with a sensitive name (the keys above and {@see self::SENSITIVE_URL_PARAMETERS}) — also a
 *   URL quoted inside an error message;
 * - the literal secrets of the step are removed from every string ({@see SecretRedactor}).
 *
 * A deny-list: a secret under an innocent name, or in a response body without a sensitive key, stays visible unless it
 * is one of the literals. That residual risk is accepted (flow-node-trace design, R-2).
 */
final readonly class TracePayloadMasker
{
    public const string MASK = '***';

    /** @var list<string> */
    public const array SENSITIVE_KEYS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'apikey',
        'x-auth-token',
        'x-access-token',
        'password',
        'passwd',
        'secret',
        'client_secret',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'api_key',
        // The http transport's `auth.bearer` parameter.
        'bearer',
    ];

    /** @var list<string> query parameter names masked in URLs on top of {@see self::SENSITIVE_KEYS} */
    public const array SENSITIVE_URL_PARAMETERS = ['key', 'sig', 'signature', 'code'];

    private const string URL_PATTERN = '#https?://[^\s"\'<>`]+#i';

    /**
     * @param  list<string>  $secrets  literal secrets of the step (credential values, secret variables)
     */
    public function mask(mixed $value, array $secrets = []): mixed
    {
        return SecretRedactor::redact($this->maskStructure($value), $secrets);
    }

    public function isSensitiveKey(string $name): bool
    {
        return self::matches(mb_strtolower($name), self::SENSITIVE_KEYS);
    }

    /**
     * Whether a URL query parameter of this name carries a secret: a sensitive key or one of
     * {@see self::SENSITIVE_URL_PARAMETERS}.
     */
    public function isSensitiveQueryParameter(string $name): bool
    {
        $lowerName = mb_strtolower($name);

        return self::matches($lowerName, self::SENSITIVE_KEYS) || self::matches($lowerName, self::SENSITIVE_URL_PARAMETERS);
    }

    public function maskUrls(string $text): string
    {
        if (false === mb_stripos($text, 'http')) {
            return $text;
        }

        return (string) preg_replace_callback(
            self::URL_PATTERN,
            fn (array $match): string => $this->maskUrl($match[0]),
            $text,
        );
    }

    /**
     * @param  list<string>  $names  lower-case names
     */
    private static function matches(string $lowerName, array $names): bool
    {
        foreach ($names as $name) {
            if ($lowerName === $name
                || str_ends_with($lowerName, '.' . $name)
                || str_ends_with($lowerName, '-' . $name)
                || str_ends_with($lowerName, '_' . $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A key with a sensitive name, or a `query.<name>` call parameter whose name is a sensitive query parameter. The URL
     * parameter names are not matched against other keys: `sort_key` or `cache_key` outside a query is innocent.
     */
    private function isSensitiveEntry(string $key): bool
    {
        if ($this->isSensitiveKey($key)) {
            return true;
        }

        if (0 !== strncasecmp($key, 'query.', 6)) {
            return false;
        }

        return $this->isSensitiveQueryParameter(mb_substr($key, 6));
    }

    private function maskStructure(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->maskUrls($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $masked = [];

        foreach ($value as $key => $item) {
            $masked[$key] = is_string($key) && $this->isSensitiveEntry($key)
                ? self::MASK
                : $this->maskStructure($item);
        }

        return $masked;
    }

    private function maskUrl(string $url): string
    {
        // The password of `scheme://user:password@host`.
        $url = (string) preg_replace('#^(https?://[^/?\#@:\s]*):[^/?\#@\s]*@#i', '$1:' . self::MASK . '@', $url);

        $queryStart = mb_strpos($url, '?');

        if (false === $queryStart) {
            return $url;
        }

        $fragmentStart = mb_strpos($url, '#', $queryStart);
        $query         = false === $fragmentStart
            ? mb_substr($url, $queryStart + 1)
            : mb_substr($url, $queryStart + 1, $fragmentStart - $queryStart - 1);
        $rest = false === $fragmentStart ? '' : mb_substr($url, $fragmentStart);

        $pairs = explode('&', $query);

        foreach ($pairs as $index => $pair) {
            $separator = mb_strpos($pair, '=');

            if (false === $separator) {
                continue;
            }

            if ($this->isSensitiveQueryParameter(urldecode(mb_substr($pair, 0, $separator)))) {
                $pairs[$index] = mb_substr($pair, 0, $separator) . '=' . self::MASK;
            }
        }

        return mb_substr($url, 0, $queryStart + 1) . implode('&', $pairs) . $rest;
    }
}
