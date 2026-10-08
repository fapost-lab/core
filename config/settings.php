<?php

declare(strict_types=1);

use App\Domains\Tenancy\Settings\TenantSettings;
use Spatie\LaravelData\Data;
use Spatie\LaravelSettings\SettingsCasts\DataCast;
use Spatie\LaravelSettings\SettingsCasts\DateTimeInterfaceCast;
use Spatie\LaravelSettings\SettingsCasts\DateTimeZoneCast;
use Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository;
use Spatie\LaravelSettings\SettingsRepositories\RedisSettingsRepository;

return [
    'settings' => [
        TenantSettings::class,
    ],

    'tenant_settings' => [
        TenantSettings::class,
    ],

    'setting_class_path' => app_path('Settings'),

    // Empty on purpose: spatie would register `database/settings` with the migrator, so every
    // plain `migrate` (including `migrate --database=landlord`) would create the tenant `settings`
    // table outside a tenant schema. Settings migrations run per tenant, through
    // `MigrationScope::settings()`.
    'migrations_paths' => [],

    'default_repository' => 'database',

    'repositories' => [
        'database' => [
            'type'       => DatabaseSettingsRepository::class,
            'model'      => null,
            'table'      => null,
            'connection' => null,
        ],
        'redis' => [
            'type'       => RedisSettingsRepository::class,
            'connection' => null,
            'prefix'     => null,
        ],
    ],

    'encoder' => null,
    'decoder' => null,

    'cache' => [
        'enabled' => env('SETTINGS_CACHE_ENABLED', false),
        'store'   => null,
        'prefix'  => null,
        'ttl'     => null,
        'memo'    => env('SETTINGS_CACHE_MEMO', false),
    ],

    'global_casts' => [
        DateTimeInterface::class => DateTimeInterfaceCast::class,
        DateTimeZone::class      => DateTimeZoneCast::class,
        Data::class              => DataCast::class,
    ],

    'auto_discover_settings' => [
        app_path('Settings'),
    ],

    'discovered_settings_cache_path' => base_path('bootstrap/cache'),
];
