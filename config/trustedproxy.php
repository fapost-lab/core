<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Read by Laravel's TrustProxies middleware. Behind a reverse proxy the socket peer is the proxy,
| so without this every client has the proxy's address and anything keyed on the client IP (the
| sign-up limits of an operator package, login throttling) treats all callers as one.
|
| TRUSTED_PROXIES is a comma-separated list of addresses or CIDR ranges, or `*`. `*` trusts
| whoever connects directly: use it only when nothing but the proxy can reach the application.
| Empty trusts nobody, which is how the application behaved before this setting existed. The
| bundled Compose file supplies a default for its own network; see docs/site/self-hosting.
|
| The headers believed from a trusted proxy are fixed in bootstrap/app.php.
|
*/

return [
    'proxies' => '' === mb_trim((string) env('TRUSTED_PROXIES', '')) ? null : mb_trim((string) env('TRUSTED_PROXIES')),
];
