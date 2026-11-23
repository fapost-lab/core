<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use RuntimeException;

/**
 * A consent that did not end in a grant. `reason` is a fixed word the console turns into a message; `providerCode` is
 * the provider's `error` code when it refused, and the only thing of its answer that is kept. Nothing here carries a
 * code, a token, a state or a secret.
 */
final class OAuth2ConsentFailedException extends RuntimeException
{
    /** The attempt is unknown, used, expired, another user's, or its credential changed or is gone. */
    public const string STALE = 'stale';

    /** The provider sent the person back with an `error`. */
    public const string PROVIDER_ERROR = 'provider_error';

    /** The code could not be exchanged for a token. */
    public const string EXCHANGE_FAILED = 'exchange_failed';

    /** Another write of the grant held its lock. */
    public const string LOCK_BUSY = 'lock_busy';

    private function __construct(
        public readonly string $reason,
        public readonly ?string $credentialId,
        public readonly ?string $providerCode = null,
    ) {
        parent::__construct('OAuth2 consent failed: ' . $reason);
    }

    public static function stale(?string $credentialId = null): self
    {
        return new self(self::STALE, $credentialId);
    }

    public static function providerError(string $credentialId, ?string $code): self
    {
        return new self(self::PROVIDER_ERROR, $credentialId, $code ?? 'unknown_error');
    }

    public static function exchangeFailed(string $credentialId): self
    {
        return new self(self::EXCHANGE_FAILED, $credentialId);
    }

    public static function lockBusy(string $credentialId): self
    {
        return new self(self::LOCK_BUSY, $credentialId);
    }
}
