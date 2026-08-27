<?php

declare(strict_types=1);

return [
    'base_url' => env('WEBHOOK_BASE_URL', env('APP_URL')),

    /*
    |--------------------------------------------------------------------------
    | Ingress routing
    |--------------------------------------------------------------------------
    |
    | Selects which ingress runtime newly registered channels are pointed at.
    | Both runtimes stay valid at all times: the Laravel route is never taken
    | down, so channels registered before a switch keep working unchanged and
    | migration is a matter of re-registering them at your own pace.
    |
    | Which platforms the gateway can handle is deliberately not configured here.
    | It is derived from the adapters themselves — a platform whose adapter
    | publishes an ingress spec can be verified by any runtime, one that keeps
    | verification in code stays on the Laravel route. A hand-maintained list
    | could only ever agree with that or be wrong.
    |
    */
    'ingress' => [
        'driver' => env('WEBHOOK_INGRESS_DRIVER', 'laravel'),

        'gateway_url' => env('WEBHOOK_GATEWAY_URL'),
    ],
];
