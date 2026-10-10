<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Refusals retention
    |--------------------------------------------------------------------------
    | How many days the record of work a per-period limit turned away is kept
    | in the tenant schema. `limit-refusals:prune` removes older days.
    */
    'refusals_retention_days' => 90,
];
