<?php

declare(strict_types=1);

use App\Domains\Audit\Models\ActivityEntry;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

/*
|--------------------------------------------------------------------------
| spatie/laravel-activitylog, as the audit log uses it
|--------------------------------------------------------------------------
| The package is the storage engine of the Audit domain, nothing more. Core records every entry explicitly through
| `AuditTrailInterface` (never the `activity()` helper or the `LogsActivity` trait), in the tenant's own schema, in the
| transaction of the change it records. Two switches are therefore fixed here and deliberately not read from the
| environment: logging is always on (a disabled logger would let an action pass unrecorded) and the buffer is always
| off (it flushes after a worker restored the tenant, into another schema, and bulk-inserts past the ULID keys).
|
| Retention is the platform setting `audit.retention_days` (config/audit.php) applied by `audit:prune`; the package's
| `activitylog:clean` does not walk the tenants and is not used, so `clean_after_days` is not read.
*/
return [
    'enabled' => true,

    'clean_after_days' => 365,

    'default_log_name' => 'default',

    'default_auth_driver' => 'web',

    'include_soft_deleted_subjects' => false,

    'activity_model' => ActivityEntry::class,

    /*
     * Keys whose value never reaches an entry. The package applies this list only to its trait; `AuditTrail` drops
     * these keys from every explicit entry's old and new values and its properties, as a safety net behind the
     * per-action field whitelist, which is the actual mechanism. A changed-field marker (the name alone, `config` or
     * `password` changed) is not affected: it carries no value.
     */
    'default_except_attributes' => [
        'password',
        'remember_token',
        'token',
        'bot_token',
        'secret',
        'secret_token',
        'config',
        'webhook_public_hash',
        'activation_token',
        'api_key',
    ],

    'buffer' => [
        'enabled' => false,
    ],

    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log'    => CleanActivityLogAction::class,
    ],
];
