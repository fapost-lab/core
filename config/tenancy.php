<?php

declare(strict_types=1);

return [
    'landlord_connection' => env('LANDLORD_DB_CONNECTION', 'pgsql'),
    'tenant_connection'   => env('TENANT_DB_CONNECTION', 'pgsql'),
    'schema_prefix'       => env('TENANT_SCHEMA_PREFIX', 'tenant_'),
    'redis_prefix'        => env('TENANT_REDIS_PREFIX', 'fapost'),
    'migration_paths'     => [
        'tenant'   => database_path('migrations/tenant'),
        'features' => database_path('migrations/features'),
    ],
];
