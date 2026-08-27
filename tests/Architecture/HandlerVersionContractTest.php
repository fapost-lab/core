<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Fapost\Foundation\Contracts\NodeHandlerInterface;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class HandlerVersionContractTest
{
    public function test_core_flow_handlers_implement_node_handler_interface(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('App\\Domains\\Flow\\Handlers', true),
                    Selector::NoneOf(
                        Selector::inNamespace('App\\Domains\\Flow\\Handlers\\Abstract', true),
                    ),
                ),
            )
            ->should()
            ->implement()
            ->classes(Selector::classname(NodeHandlerInterface::class))
            ->because(
                'HandlerVersionContract: every core flow handler must implement NodeHandlerInterface.',
            );
    }

    public function test_condition_handler_does_not_depend_on_database_layers(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::classname('App\\Domains\\Flow\\Handlers\\BranchNodeHandler'),
            )
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Illuminate\\Database', true),
                Selector::classname('Illuminate\\Support\\Facades\\DB'),
                Selector::classname('Illuminate\\Support\\Facades\\Schema'),
            )
            ->because(
                'Condition node must resolve module.* via DataAccessor and must not read database directly.'
            );
    }
}
