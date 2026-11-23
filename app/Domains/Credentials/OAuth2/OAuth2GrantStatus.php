<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

/**
 * The state of a stored grant. A credential without a grant is "not connected"; that state has no row.
 */
enum OAuth2GrantStatus: string
{
    /** The tokens are usable, or can be refreshed. */
    case Connected = 'connected';

    /** The provider refused the grant (revoked, expired): only a new consent brings it back. */
    case ReconnectNeeded = 'reconnect_needed';
}
