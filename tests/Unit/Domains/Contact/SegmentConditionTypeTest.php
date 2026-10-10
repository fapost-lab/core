<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Contact;

use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentOperator;
use App\Domains\Contact\Enums\SegmentValueArity;
use PHPUnit\Framework\TestCase;

/**
 * The type decides which operators a segment condition may use and how many values it takes; the console validates
 * against this and the resolver reads a condition the same way.
 */
final class SegmentConditionTypeTest extends TestCase
{
    public function test_each_type_offers_its_operators_with_the_default_first(): void
    {
        $this->assertSame([SegmentOperator::Has, SegmentOperator::NotHas], SegmentConditionType::Tag->operators());
        $this->assertSame([SegmentOperator::Eq, SegmentOperator::Ne, SegmentOperator::Exists], SegmentConditionType::Attribute->operators());
        $this->assertSame([SegmentOperator::In, SegmentOperator::NotIn], SegmentConditionType::Group->operators());
        $this->assertSame([SegmentOperator::In, SegmentOperator::Eq], SegmentConditionType::Language->operators());
        $this->assertSame([SegmentOperator::In, SegmentOperator::Eq], SegmentConditionType::Platform->operators());
    }

    public function test_only_an_attribute_needs_a_key(): void
    {
        foreach (SegmentConditionType::cases() as $type) {
            $this->assertSame(SegmentConditionType::Attribute === $type, $type->needsKey(), $type->value);
        }
    }

    public function test_arity_matches_what_the_resolver_reads(): void
    {
        $this->assertSame(SegmentValueArity::One, SegmentConditionType::Tag->arity(SegmentOperator::Has));
        $this->assertSame(SegmentValueArity::One, SegmentConditionType::Tag->arity(SegmentOperator::NotHas));
        $this->assertSame(SegmentValueArity::One, SegmentConditionType::Attribute->arity(SegmentOperator::Eq));
        $this->assertSame(SegmentValueArity::One, SegmentConditionType::Attribute->arity(SegmentOperator::Ne));
        $this->assertSame(SegmentValueArity::None, SegmentConditionType::Attribute->arity(SegmentOperator::Exists));
        $this->assertSame(SegmentValueArity::One, SegmentConditionType::Language->arity(SegmentOperator::Eq));
        $this->assertSame(SegmentValueArity::Many, SegmentConditionType::Language->arity(SegmentOperator::In));
        $this->assertSame(SegmentValueArity::One, SegmentConditionType::Platform->arity(SegmentOperator::Eq));
        $this->assertSame(SegmentValueArity::Many, SegmentConditionType::Platform->arity(SegmentOperator::In));
        $this->assertSame(SegmentValueArity::Many, SegmentConditionType::Group->arity(SegmentOperator::In));
        $this->assertSame(SegmentValueArity::Many, SegmentConditionType::Group->arity(SegmentOperator::NotIn));
    }
}
