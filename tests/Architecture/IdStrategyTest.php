<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Flow\Models\FlowCallgraphEdge;
use App\Domains\Presale\Models\PreSaleRequest;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
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
                    Selector::AnyOf(
                        Selector::inNamespace('#^App\\\\Domains\\\\.*\\\\Models$#', true),
                        // Eloquent models kept next to a store, such as the Solution activation records.
                        Selector::AllOf(
                            Selector::inNamespace('#^App\\\\Domains\\\\.*\\\\Infrastructure$#', true),
                            Selector::extends(Model::class),
                        ),
                    ),
                    Selector::NoneOf(
                        Selector::isEnum(),
                        Selector::classname(PreSaleRequest::class),
                        // Composite primary key (caller_flow_id, callee_flow_id,
                        // caller_definition_id) — the row has no surrogate id column
                        // for a ULID to occupy.
                        Selector::classname(FlowCallgraphEdge::class),
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
