<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Presale\Models\PreSaleRequest;
use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class IdStrategyTest
{
    public function test_domain_models_use_has_ulid_primary_key(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('^App\\\\Domains\\\\.*\\\\Models$', true),
                    Selector::NoneOf(
                        Selector::isEnum(),
                        Selector::classname(PreSaleRequest::class),
                    ),
                ),
            )
            ->should()
            ->include()
            ->classes(Selector::classname(HasUlidPrimaryKey::class))
            ->because(
                'ADR-03: primary keys are ULIDs stored in PostgreSQL uuid columns; Laravel generates them via HasUlidPrimaryKey.',
            );
    }
}
