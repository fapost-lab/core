<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Egress;

use RuntimeException;

/**
 * Thrown by {@see EgressGuardMiddleware} when a request, or a redirect hop, targets
 * an address the call node may not reach.
 *
 * Deliberately not a ConnectionException: a refused target is a policy decision,
 * not a network failure, and the transport reports it under its own error code.
 */
final class EgressDeniedException extends RuntimeException
{
    /**
     * @param  list<string>  $addresses  resolved addresses, for the operator log only
     */
    public function __construct(
        public readonly string $host,
        public readonly string $reason,
        public readonly array $addresses = [],
    ) {
        parent::__construct("Egress to {$host} denied: {$reason}.");
    }
}
