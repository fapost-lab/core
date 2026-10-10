<?php

declare(strict_types=1);

namespace App\Domains\Contact\Enums;

/**
 * Contact attribute a segment condition filters on: tag membership, content
 * language, platform, a flow-collected value in the `attributes` json
 * (dot-path key), or {@see \App\Domains\Contact\Models\ContactGroup} membership.
 *
 * The type is also the single source of which operators a condition may use and how many values it takes: the
 * Filament form and the console form offer exactly these, the console validates against them, and the
 * {@see \App\Domains\Contact\Services\ContactSegmentResolver} reads a condition the same way.
 */
enum SegmentConditionType: string
{
    case Tag       = 'tag';
    case Language  = 'language';
    case Platform  = 'platform';
    case Attribute = 'attribute';
    case Group     = 'group';

    /**
     * The operators this type accepts; the first is the default.
     *
     * @return list<SegmentOperator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Tag                      => [SegmentOperator::Has, SegmentOperator::NotHas],
            self::Attribute                => [SegmentOperator::Eq, SegmentOperator::Ne, SegmentOperator::Exists],
            self::Group                    => [SegmentOperator::In, SegmentOperator::NotIn],
            self::Language, self::Platform => [SegmentOperator::In, SegmentOperator::Eq],
        };
    }

    /**
     * How many values the condition reads with the operator. A tag, and an attribute or column compared for
     * equality, use the first value only, so asking for more would silently ignore the rest.
     */
    public function arity(SegmentOperator $operator): SegmentValueArity
    {
        if (SegmentOperator::Exists === $operator) {
            return SegmentValueArity::None;
        }

        if (self::Tag === $this || SegmentOperator::Eq === $operator || SegmentOperator::Ne === $operator) {
            return SegmentValueArity::One;
        }

        return SegmentValueArity::Many;
    }

    /**
     * Whether the condition names a key inside the contact's attributes.
     */
    public function needsKey(): bool
    {
        return self::Attribute === $this;
    }
}
