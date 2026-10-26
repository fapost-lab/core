<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class OnboardingBoundariesTest
{
    public function test_onboarding_domain_depends_on_no_other_domain_but_the_tenancy_contracts(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Domains\\Onboarding'))
            ->shouldNotDependOn()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('#^App\\\\Domains\\\\#', true),
                    Selector::NoneOf(
                        Selector::inNamespace('App\\Domains\\Onboarding'),
                        Selector::inNamespace('App\\Domains\\Tenancy\\Contracts'),
                    ),
                ),
            )
            ->because('The wizard registry stays neutral so that Core, Solutions and operator packages can add steps to it. The steps that know Assistant or Flow live in App\\Infrastructure\\Onboarding; the one exception is the Tenancy contracts, since the state belongs to a tenant.');
    }

    public function test_the_domains_do_not_depend_on_the_onboarding_infrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('App\\Domains\\Onboarding'),
                Selector::inNamespace('App\\Domains\\Tenancy'),
            )
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('App\\Infrastructure\\Onboarding'))
            ->because('The dependency points from Infrastructure to the domains, never back; Tenancy reaches the wizard through NewTenantInitializerInterface.');
    }
}
