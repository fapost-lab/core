<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\AssistantService;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * `new X` is all PHPat can see; static creates (`X::create()`, `X::query()->create()`) are
 * covered by `Tests\Unit\Architecture\CountableModelCreationTest`. Both read the countable models
 * from `Tests\Support\CountableModels`.
 */
final class CountableModelCreationTest
{
    public function test_assistant_is_constructed_only_by_assistant_service(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('App'),
                    Selector::NoneOf(
                        Selector::classname(AssistantService::class),
                        Selector::classname(Assistant::class),
                    ),
                ),
            )
            ->shouldNotConstruct()
            ->classes(Selector::classname(Assistant::class))
            ->because('The tenant assistant limit is checked in AssistantService::create(); constructing an Assistant elsewhere bypasses it.');
    }
}
