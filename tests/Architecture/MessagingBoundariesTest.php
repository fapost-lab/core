<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class MessagingBoundariesTest
{
    public function test_flow_domain_does_not_depend_on_provider_sender_or_telegram_implementation(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Domains\\Flow', true))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('Fapost\\Foundation\\Messaging\\ProviderSenderInterface'),
                Selector::inNamespace('App\\Domains\\Messaging\\Telegram', true),
            )
            ->because('Flow domain must stay decoupled from messaging provider implementations.');
    }
}
