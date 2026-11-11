<?php

declare(strict_types=1);

use App\Domains\Audit\Services\AuditRetention;

return [
    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | The default of the platform setting `audit.retention_days` (Platform settings, or `platform:settings:set`); a
    | stored value wins. How many days an audit log entry is kept, in every tenant: the daily `audit:prune` deletes
    | older entries. 0 keeps them forever; unset is 365.
    */
    'retention_days' => AuditRetention::parseDays(env('AUDIT_RETENTION_DAYS')),
];
