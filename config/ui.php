<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | New Inertia console
    |--------------------------------------------------------------------------
    |
    | When on, `routes/inertia.php` is registered after the Filament panels and replaces the
    | Filament routes it redeclares (same method, domain, URI and name). Routes are cached at
    | container start, so the switch takes effect only after a restart; tests set the
    | `UI_INERTIA` environment variable before the application boots.
    |
    */

    'inertia' => (bool) env('UI_INERTIA', false),

];
