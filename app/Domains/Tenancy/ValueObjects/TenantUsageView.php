<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * Everything the usage page and the dashboard widget show for the current tenant.
 */
final readonly class TenantUsageView
{
    /**
     * @param  list<UsageRow>  $rows
     * @param  array{label: string, url: string}|null  $action  the operator's button, for example "Upgrade plan"
     * @param  bool  $limited  whether at least one row has a limit, or could not be read (so "no limits" is never said of an unknown)
     */
    public function __construct(
        public array $rows,
        public ?array $action,
        public bool $limited,
    ) {
    }
}
