<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class SolutionBoundariesTest
{
    public function test_solution_domain_depends_on_no_other_domain_but_the_tenancy_contracts(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Domains\\Solution'))
            ->shouldNotDependOn()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('#^App\\\\Domains\\\\#', true),
                    Selector::NoneOf(
                        Selector::inNamespace('App\\Domains\\Solution'),
                        Selector::inNamespace('App\\Domains\\Tenancy\\Contracts'),
                    ),
                ),
            )
            ->because('The Solution domain sits below the domains a Solution extends. The one exception is the Tenancy contracts: activation belongs to a tenant, and Tenancy sits below everything.');
    }

    public function test_solution_discovery_does_not_touch_the_database_or_facades(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('App\\Domains\\Solution'),
                    Selector::NoneOf(
                        Selector::inNamespace('App\\Domains\\Solution\\Infrastructure'),
                        Selector::inNamespace('App\\Domains\\Solution\\Providers'),
                    ),
                ),
            )
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Illuminate\\Support\\Facades'),
                Selector::inNamespace('Illuminate\\Database'),
            )
            ->because('Discovery must run without a database (platform:update --validate-only) and read its collaborators from the constructor.');
    }

    public function test_flow_does_not_depend_on_the_solution_domain(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('App\\Domains\\Flow'),
                Selector::inNamespace('App\\Infrastructure\\Flow'),
            )
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('App\\Domains\\Solution'))
            ->because('Flow asks whether an action may run through ActionAvailabilityInterface; App\\Infrastructure\\Solution answers it from the Solution domain. The registry and the handler transport are singletons and must not hold the scoped ActiveSolutions.');
    }

    public function test_channels_and_media_depend_on_neither_the_solution_domain_nor_infrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('App\\Domains\\Channels'),
                Selector::inNamespace('App\\Domains\\Media'),
            )
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\\Domains\\Solution'),
                Selector::inNamespace('App\\Infrastructure'),
            )
            ->because('Channels and Media are filled by connectors through registrars that live in App\\Infrastructure\\Solution; the dependency points from Infrastructure to the domains, never back.');
    }
}
