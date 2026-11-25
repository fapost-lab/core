<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Contracts;

use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;

/**
 * A credential type whose authorization may make requests of its own (an OAuth2 token request, the wait for a refresh
 * lock), and which can keep them inside a time limit. Core-only: the HTTP transport passes the call's timeout, so a
 * cold token cache cannot stretch a call past the window the flow runtime sizes its jobs and locks by.
 */
interface AuthorizesWithinDeadlineInterface
{
    /**
     * As {@see \Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface::authorize()}, spending at most
     * `$seconds` on the requests and waits it makes.
     *
     * @throws CredentialUnavailableException when the type cannot authorize, or not in time
     */
    public function authorizeWithin(CredentialSecret $secret, float $seconds): HttpAuthorization;
}
