<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use RuntimeException;

/**
 * The lock of a credential's grant could not be taken in time (another worker is refreshing it), or the cache store
 * offers no locks. A grant is never refreshed without the lock.
 */
final class OAuth2GrantLockBusy extends RuntimeException
{
}
