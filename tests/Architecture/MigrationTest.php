<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class MigrationTest
{
    public function test_migrations_do_not_depend_on_application_services(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::withFilepath('/database\\/migrations\\//', true),
            )
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\\Domains'),
                Selector::inNamespace('App\\Services'),
            )
            ->because(
                'Migrations are pure DDL. They must not depend on application services, ' .
                'tenant context, or module state. See: Migration Isolation Contract in CLAUDE.md'
            );
    }
}
