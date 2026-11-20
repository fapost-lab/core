<?php

declare(strict_types=1);

namespace App\Domains\Staff\Support;

/**
 * Names of the tenant API token's guard, its driver, the middleware group of its routes and its rate limiter.
 */
final class ApiTokenGuard
{
    /** The guard in `config/auth.php` and in `auth:api-token`. */
    public const string NAME = 'api-token';

    /** The request-guard driver registered with `Auth::viaRequest()`. */
    public const string DRIVER = 'api-token';

    /** The middleware group of the API routes (`routes/api.php`). */
    public const string MIDDLEWARE_GROUP = 'api-token';

    /** The named rate limiter of `throttle:api-token`. */
    public const string LIMITER = 'api-token';
}
