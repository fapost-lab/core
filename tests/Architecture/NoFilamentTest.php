<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Core's code must not depend on Filament or Livewire.
 *
 * PHPat checks only targets it can reflect, so this rule bites where Filament is installed (Core with the SaaS
 * overlay, in the SaaS package's CI). In Core's own CI those classes are absent and the rule passes vacuously; there
 * a reference to them fails phpstan's unknown-class checks (level 0) instead.
 */
final class NoFilamentTest
{
    public function test_core_does_not_depend_on_filament_or_livewire(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Filament'),
                Selector::inNamespace('Livewire'),
            )
            ->because('Core\'s console is Inertia and Vue; Filament and Livewire left Core and may be installed only by a package such as the SaaS shell, for its own panel.');
    }
}
