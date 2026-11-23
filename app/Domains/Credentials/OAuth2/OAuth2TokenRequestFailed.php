<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use RuntimeException;

/**
 * A token request that gave no token. The message is the reason in English; it, the status and the provider's `error`
 * code are all it carries — never a response body, a token or a client secret, and never a previous exception.
 */
final class OAuth2TokenRequestFailed extends RuntimeException
{
    /** The `error` codes that say the grant or the client is no longer accepted (RFC 6749 §5.2). */
    private const array REJECTIONS = ['invalid_grant', 'invalid_client', 'unauthorized_client'];

    public function __construct(
        string $reason,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($reason);
    }

    /**
     * Whether the provider refused the grant for good (a 400 or 401 with a rejection code), as opposed to a failure
     * that may pass: a 5xx, no answer, the egress guard.
     */
    public function isRejection(): bool
    {
        return in_array($this->status, [400, 401], true) && in_array($this->errorCode, self::REJECTIONS, true);
    }
}
