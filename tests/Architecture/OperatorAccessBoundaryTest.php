<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Staff\Services\CoreOperatorAccess;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class OperatorAccessBoundaryTest
{
    public function test_core_reaches_the_operator_access_decision_only_through_the_contract(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::classname(CoreOperatorAccess::class),
                Selector::classname('App\\Providers\\StaffServiceProvider'),
            )
            ->shouldNotDependOn()
            ->classes(Selector::classname(CoreOperatorAccess::class))
            ->because('An operator layer rebinds OperatorAccessInterface; asking Core\'s default directly would bypass it.');
    }
}
