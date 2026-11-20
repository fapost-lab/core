<?php

declare(strict_types=1);

namespace App\Domains\Staff\Enums;

/**
 * What an installation operator's revoke of a leaked tenant API token came to.
 */
enum OperatorRevokeOutcome: string
{
    case Revoked        = 'revoked';
    case AlreadyRevoked = 'already_revoked';

    /** Not a token of this tenant, a malformed value, or a secret that does not match: told apart nowhere. */
    case NotFound = 'not_found';
}
