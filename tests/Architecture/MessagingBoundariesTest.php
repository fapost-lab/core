<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class MessagingBoundariesTest
{
    public function test_flow_domain_does_not_depend_on_provider_sender_or_channel_provider_modules(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Domains\\Flow'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('Fapost\\Foundation\\Messaging\\ProviderSenderInterface'),
                $this->channelProviderModules(),
            )
            ->because('Flow domain must stay decoupled from messaging provider implementations.');
    }

    /**
     * Every provider module under Channels (`Telegram`, `WhatsApp`, and any added later): each
     * sub-namespace of `App\Domains\Channels` except the domain's shared layers. Classes directly
     * in `App\Domains\Channels` are shared too and fall outside the regex.
     *
     * The shared layers are listed rather than the providers so a new provider is covered
     * without touching this rule. A new shared layer that Flow legitimately uses fails the
     * rule loudly and belongs in the list below.
     */
    private function channelProviderModules(): SelectorInterface
    {
        return Selector::AllOf(
            Selector::inNamespace('#^App\\\\Domains\\\\Channels\\\\#', true),
            Selector::NoneOf(
                Selector::inNamespace('App\\Domains\\Channels\\Contracts'),
                Selector::inNamespace('App\\Domains\\Channels\\Enums'),
                Selector::inNamespace('App\\Domains\\Channels\\Models'),
                Selector::inNamespace('App\\Domains\\Channels\\Observers'),
                Selector::inNamespace('App\\Domains\\Channels\\Policies'),
                Selector::inNamespace('App\\Domains\\Channels\\Providers'),
                Selector::inNamespace('App\\Domains\\Channels\\Services'),
            ),
        );
    }
}
