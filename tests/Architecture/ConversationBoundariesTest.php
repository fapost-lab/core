<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ConversationBoundariesTest
{
    public function test_conversation_domain_does_not_depend_on_flow(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Domains\\Conversation'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\\Domains\\Flow'),
                Selector::inNamespace('App\\Jobs\\Flow'),
                Selector::inNamespace('App\\Infrastructure\\Flow'),
            )
            ->because('Conversation is the transcript and the operator thread; Flow reaches it, never the other way round, so a conversation can be read, held and answered with no flow runtime loaded.');
    }
}
