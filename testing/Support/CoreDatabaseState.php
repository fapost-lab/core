<?php

declare(strict_types=1);

namespace Fapost\Testing\Support;

use Fapost\Testing\Concerns\PreparesCoreDatabase;

/**
 * What this process last did to the test databases, shared by every class using {@see PreparesCoreDatabase}.
 *
 * A static property declared in a trait is copied into each class that uses it, so Core's `FeatureTestCase` and the
 * kit's `SolutionTestCase` would each keep their own migration signature while Laravel's "already migrated" flag is one
 * per process. A run of Feature (signature X) → Solution (S) → Feature (X) would then skip the migration and leave
 * schema S in place. Keeping the state here makes it one per process again.
 *
 * @internal not part of the testing kit's API
 */
final class CoreDatabaseState
{
    /**
     * Migration signature this process last migrated for, or null before the first run.
     */
    public static ?string $migratedSignature = null;

    /**
     * Landlord databases this process has already made sure exist.
     *
     * @var array<string, true>
     */
    public static array $ensuredLandlordDatabases = [];
}
