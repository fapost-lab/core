<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class CurrentAssistantSourceTest
{
    public function test_domain_and_worker_code_does_not_read_the_assistant_from_filament(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace('App\\Domains\\Assistant'),
                Selector::inNamespace('App\\Domains\\Flow'),
                Selector::inNamespace('App\\Domains\\Webhook'),
                Selector::inNamespace('App\\Jobs'),
                Selector::inNamespace('App\\Infrastructure'),
            )
            ->shouldNotDependOn()
            ->classes(Selector::classname('Filament\\Facades\\Filament'))
            ->because('The current assistant is set explicitly (console or panel middleware, job payload); domain and worker code must not read it from Filament tenancy.');
    }
}
