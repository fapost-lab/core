<?php

declare(strict_types=1);

return [
    'landlord_connection' => env('LANDLORD_DB_CONNECTION', 'pgsql'),
    'tenant_connection'   => env('TENANT_DB_CONNECTION', 'pgsql'),
    'base_domain'         => env('TENANCY_BASE_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
    'default_tenant_slug' => env('TENANT_SLUG', 'app'),
    'schema_prefix'       => env('TENANT_SCHEMA_PREFIX', 'tenant_'),
    'redis_prefix'        => env('TENANT_REDIS_PREFIX', 'fapost'),
    'migration_paths'     => [
        'tenant'   => database_path('migrations/tenant'),
        'features' => database_path('migrations/features'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant resolution
    |--------------------------------------------------------------------------
    |
    | single - one tenant per deployment, taken from TENANT_SLUG; the request
    |          host is not read.
    | host   - the tenant is named by the request host, <slug>.<base_domain>.
    |          The base domain itself serves platform pages without a tenant,
    |          and any other host is answered with 404.
    |
    | Any other value fails at boot.
    |
    */
    'resolution' => env('TENANCY_RESOLUTION', 'single'),

    /*
    |--------------------------------------------------------------------------
    | Reserved tenant slugs
    |--------------------------------------------------------------------------
    |
    | On a subdomain deployment a slug becomes a hostname the tenant controls,
    | so these names must never be assignable. The ingress hostnames are added
    | automatically from the webhook configuration — see DomainServiceProvider —
    | because a tenant answering there would receive other tenants' webhooks,
    | whose headers carry their channel secrets.
    |
    | The configured default tenant slug is exempted at bind time so that a
    | stock installation still provisions.
    |
    */
    'reserved_slugs' => [
        // Platform surfaces
        'www', 'api', 'admin', 'administrator', 'app', 'dashboard', 'panel', 'console',
        'auth', 'login', 'logout', 'register', 'signup', 'id', 'account', 'accounts',
        'billing', 'status', 'health', 'metrics', 'monitor', 'grafana', 'horizon',

        // Content and assets
        'cdn', 'static', 'assets', 'media', 'files', 'img', 'images',
        'docs', 'doc', 'help', 'support', 'blog', 'news', 'about', 'legal', 'terms', 'privacy',

        // Ingress and networking
        'gateway', 'ingress', 'webhook', 'webhooks', 'hooks', 'callback', 'callbacks',
        'vpn', 'proxy', 'ws', 'wss', 'rpc',

        // Mail and name service
        'mail', 'email', 'smtp', 'imap', 'pop', 'pop3', 'webmail',
        'ns', 'ns1', 'ns2', 'ns3', 'mx', 'ftp', 'sftp',

        // Delegation labels: controlling these allows mail spoofing or certificate issuance
        'autodiscover', 'autoconfig', '_dmarc', '_domainkey', '_acme-challenge',

        // Environments
        'test', 'staging', 'stage', 'dev', 'demo', 'sandbox', 'local', 'internal', 'preview',
    ],
];
