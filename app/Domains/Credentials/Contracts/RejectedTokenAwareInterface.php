<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Contracts;

use Fapost\Foundation\Credentials\DTO\CredentialSecret;

/**
 * A credential type whose authorization carries a token it obtained itself (OAuth2), and which must stop using that
 * token once the target answered it with 401. Core-only: the HTTP transport asks the type, not its id.
 */
interface RejectedTokenAwareInterface
{
    /**
     * The target refused the token the type applied: the next call must not send it again. No request is made here.
     */
    public function tokenRejected(CredentialSecret $secret): void;
}
