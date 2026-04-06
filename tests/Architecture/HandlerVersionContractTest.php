<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Flow\Contracts\NodeHandlerInterface;
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
}
