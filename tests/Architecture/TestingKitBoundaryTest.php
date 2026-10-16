<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class TestingKitBoundaryTest
{
    public function test_core_does_not_depend_on_the_solution_testing_kit(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'), Selector::inNamespace('Database'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Fapost\\Testing'))
            ->because('The testing kit (testing/, autoload-dev) depends on Core, never the other way round: it is not installed in a production image.');
    }
}
