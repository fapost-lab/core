<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Settings;

/**
 * Remembers the {@see TenantSettings} instance the container built in this scope, as opposed to one bound with
 * `instance()` (`TenantSettings::fake()` in a test).
 *
 * A tenant switch hides only the built instance: it was loaded for the tenant that was current when it was built,
 * while a bound one was put there on purpose and stays. Bound `scoped`, so it is dropped with the instance it
 * tracks between jobs and requests.
 */
final class BuiltTenantSettings
{
    public ?TenantSettings $instance = null;
}
