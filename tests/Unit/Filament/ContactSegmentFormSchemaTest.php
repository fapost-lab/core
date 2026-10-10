<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Domains\Contact\Enums\SegmentConditionType;
use App\Filament\Assistant\Resources\ContactSegments\Schemas\ContactSegmentFormSchema;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class ContactSegmentFormSchemaTest extends TestCase
{
    public function test_conditions_repeater_spans_the_full_width(): void
    {
        $components = ContactSegmentFormSchema::configure(Schema::make())
            ->getComponents(withActions: false, withHidden: true);

        $conditions = collect($components)
            ->first(fn (object $component): bool => $component instanceof Repeater && 'conditions' === $component->getName());

        $this->assertInstanceOf(Repeater::class, $conditions);
        // Four condition fields per row are unreadable inside the form's
        // default half-width column.
        $this->assertSame(['default' => 'full'], $conditions->getColumnSpan());
    }

    public function test_value_takes_the_column_the_key_field_leaves_free(): void
    {
        $span = new ReflectionMethod(ContactSegmentFormSchema::class, 'valueColumnSpan');

        // Tag / language / platform / group hide `key`, so value gets its column.
        $this->assertSame(2, $span->invoke(null, $this->getReturning(SegmentConditionType::Tag->value)));
        $this->assertSame(2, $span->invoke(null, $this->getReturning(SegmentConditionType::Group->value)));

        // `attribute` shows `key`, and the row is back to four equal fields.
        $this->assertSame(1, $span->invoke(null, $this->getReturning(SegmentConditionType::Attribute->value)));
    }

    public function test_operator_options_per_type_are_unchanged(): void
    {
        $options = new ReflectionMethod(ContactSegmentFormSchema::class, 'operatorOptions');

        $expected = [
            'tag'       => ['has', 'not_has'],
            'attribute' => ['eq', 'ne', 'exists'],
            'group'     => ['in', 'not_in'],
            'language'  => ['in', 'eq'],
            'platform'  => ['in', 'eq'],
            ''          => ['in', 'eq'],
            'unknown'   => ['in', 'eq'],
        ];

        foreach ($expected as $type => $operators) {
            $this->assertSame($operators, array_keys($options->invoke(null, (string) $type)), "type [{$type}]");
        }

        $this->assertSame(__('segment.operators.not_has'), $options->invoke(null, 'tag')['not_has']);
    }

    /**
     * Minimal stand-in for the utility Filament injects into schema closures —
     * a real one needs a mounted Livewire component behind it.
     */
    private function getReturning(string $type): Get
    {
        return new class ($type) extends Get {
            public function __construct(private readonly string $conditionType)
            {
            }

            public function __invoke(string | Component $path = '', bool $isAbsolute = false): mixed
            {
                return 'type' === $path ? $this->conditionType : null;
            }
        };
    }
}
