<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\DTO\PublicMediaLinkClaims;
use RuntimeException;

/**
 * Signs and verifies public media links with a key derived from the application key.
 *
 * The key is a HKDF of the raw `APP_KEY` bytes under a context of its own, so a signature of this class matches no
 * Laravel `signed` URL signature and leaking one tells nothing about the other. Signing uses the current key only;
 * verification also accepts each previous key, so a rotation does not break links already in flight.
 *
 * Only {@see PublicMediaLinkIssuer} may sign and only it and {@see PublicMediaLinkResolver} may use this class
 * (PHPat: `PublicMediaLinkSignerTest`).
 */
final readonly class PublicMediaLinkSigner
{
    private const string CONTEXT = 'fapost.media.public-link.v1';

    /**
     * Keys are derived on use, never here: the dispatcher builds this class for every channel, Telegram included, and
     * the application boots without a key during `composer install` (`package:discover`) and before `key:generate`.
     * A missing or malformed key may only cost public links, never the boot or another channel's media.
     *
     * @param  string  $appKey  `app.key`, with or without the `base64:` prefix.
     * @param  list<string>  $previousKeys  `app.previous_keys`, same format.
     */
    public function __construct(
        private string $appKey,
        private array $previousKeys = [],
    ) {
    }

    public function sign(PublicMediaLinkClaims $claims): string
    {
        return $this->mac($claims, $this->derive($this->appKey));
    }

    public function verify(PublicMediaLinkClaims $claims, string $signature): bool
    {
        $valid = false;

        foreach ($this->verifyKeys() as $key) {
            // Every key is tried, so the time taken does not tell which one matched.
            $valid = hash_equals($this->mac($claims, $key), $signature) || $valid;
        }

        return $valid;
    }

    /**
     * The current key, then each previous key that derives. A malformed previous key is reported and skipped: it may
     * only cost the links it signed.
     *
     * @return list<string>
     */
    private function verifyKeys(): array
    {
        $keys = [$this->derive($this->appKey)];

        foreach ($this->previousKeys as $key) {
            try {
                $keys[] = $this->derive($key);
            } catch (RuntimeException $exception) {
                report($exception);
            }
        }

        return $keys;
    }

    private function mac(PublicMediaLinkClaims $claims, string $key): string
    {
        $raw = hash_hmac('sha256', $claims->message(), $key, true);

        return mb_rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function derive(string $key): string
    {
        if ('' === $key) {
            throw new RuntimeException('The application key is empty; public media links cannot be signed.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(mb_substr($key, 7), true);

            if (false === $decoded || '' === $decoded) {
                throw new RuntimeException('The application key is not valid base64.');
            }

            $key = $decoded;
        }

        return hash_hkdf('sha256', $key, 32, self::CONTEXT);
    }
}
