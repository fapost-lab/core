<?php

declare(strict_types=1);

namespace App\Domains\Staff\Contracts;

use App\Domains\Staff\Models\ApiToken;
use Illuminate\Http\Request;

/**
 * Finds the tenant API token a request presents, in the tenant the request already runs in
 * (`adr-20261119-tenant-api-tokens` §5, §13). The seam an OAuth resource server would implement later.
 *
 * MAY:      read only `Authorization: Bearer`; look the token up in the current tenant's schema by primary key; record
 *           when the token was last used; refuse a client that failed too often.
 * MUST:     check the token's shape before any query; compare the secret's hash with `hash_equals`; answer `null` for
 *           every failure alike (missing, malformed, unknown, wrong secret, expired, revoked) and tell them apart only
 *           in the log.
 * MUST NOT: take the tenant from the token; read a token from the query string, a form body or a path; log the token.
 * Why:      a token is a key to one tenant; any difference a caller can observe helps someone guessing.
 */
interface ApiTokenAuthenticatorInterface
{
    /**
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException when this client failed too often
     */
    public function authenticate(Request $request): ?ApiToken;
}
