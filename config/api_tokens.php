<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant API tokens
|--------------------------------------------------------------------------
| The defaults of the platform settings `api_tokens.*` (Platform settings, or `platform:settings:set`); a stored value
| wins. See App\Domains\Staff\Support\ApiTokenSettings.
*/

return [
    // Days a new token lives unless its issuer picks another expiry.
    'default_ttl_days' => (int) env('API_TOKEN_DEFAULT_TTL_DAYS', 90),

    // The longest expiry an issuer may give a token; never more than 365.
    'max_ttl_days' => (int) env('API_TOKEN_MAX_TTL_DAYS', 365),

    // Hours a rotated token keeps working next to its successor; 0 ends it at once.
    'rotation_grace_hours' => (int) env('API_TOKEN_ROTATION_GRACE_HOURS', 24),

    // Requests one token may make in a minute.
    'per_minute' => (int) env('API_TOKEN_PER_MINUTE', 60),
];
