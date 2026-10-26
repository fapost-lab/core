<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Health\Services\CoreInstallationHealth;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class HealthContractBoundaryTest
{
    public function test_core_reaches_the_installation_health_contract_only_through_its_interface(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::classname(CoreInstallationHealth::class),
                Selector::classname('App\\Domains\\Health\\Providers\\HealthServiceProvider'),
            )
            ->shouldNotDependOn()
            ->classes(Selector::classname(CoreInstallationHealth::class))
            ->because('An operator layer may rebind InstallationHealthInterface; Core\'s own screens read HealthService, not the contract\'s default.');
    }
}
