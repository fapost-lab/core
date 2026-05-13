<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Builder\Schema;

use FAPost\Support\Builder\Schema\Field;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;
use Tests\TestCase;

/**
 * Per-field-type + composition tests for the fluent Schema builder.
 * These don't go through any Vue renderer or runtime — they assert the
 * wire shape exactly, so handler migrations are mechanical: replace
 * the manual array with a fluent expression that {@see toArray()} to
 * the same thing.
 */
final class SchemaBuilderTest extends TestCase
{
    public function test_text_field_emits_minimal_shape(): void
    {
        $array = Field::string('url')->toArray();

        $this->assertSame(['type' => 'string'], $array);
    }

    public function test_text_field_emits_full_shape_with_regex(): void
    {
        $array = Field::string('event')
            ->label('Event type')
            ->required()
            ->placeholder('sales.order.created')
            ->help('Lowercase only.')
            ->regex('^[a-z][a-z0-9_.]*$', 'Lowercase only')
            ->toArray();

        $this->assertSame([
            'type'          => 'string',
            'label'         => 'Event type',
            'required'      => true,
            'placeholder'   => 'sales.order.created',
            'help'          => 'Lowercase only.',
            'regex'         => '^[a-z][a-z0-9_.]*$',
            'regex_message' => 'Lowercase only',
        ], $array);
    }

    public function test_number_field_emits_min_max(): void
    {
        $array = Field::number('timeout')
            ->label('Timeout (s)')
            ->default(10)
            ->min(1)
            ->max(300)
            ->toArray();

        $this->assertSame([
            'type'    => 'number',
            'label'   => 'Timeout (s)',
            'default' => 10,
            'min'     => 1,
            'max'     => 300,
        ], $array);
    }

    public function test_select_field_emits_options(): void
    {
        $array = Field::select('method')
            ->label('HTTP method')
            ->options(['GET', 'POST'])
            ->default('POST')
            ->toArray();

        $this->assertSame([
            'type'    => 'enum',
            'label'   => 'HTTP method',
            'default' => 'POST',
            'options' => ['GET', 'POST'],
        ], $array);
    }

    public function test_toggle_field_emits_boolean_type(): void
    {
        $array = Field::toggle('verbose')->default(true)->toArray();

        $this->assertSame(['type' => 'boolean', 'default' => true], $array);
    }

    public function test_textarea_field_emits_text_type(): void
    {
        $array = Field::text('body')->placeholder('Welcome')->toArray();

        $this->assertSame(['type' => 'text', 'placeholder' => 'Welcome'], $array);
    }

    public function test_array_field_emits_array_type(): void
    {
        $array = Field::array('include_state')->label('Include state')->toArray();

        $this->assertSame(['type' => 'array', 'label' => 'Include state'], $array);
    }

    public function test_json_field_emits_default(): void
    {
        $array = Field::json('payload')->default([])->toArray();

        $this->assertSame(['type' => 'json', 'default' => []], $array);
    }

    public function test_state_picker_field_emits_state_picker_type(): void
    {
        $array = Field::statePicker('save_to')->placeholder('flow.response')->toArray();

        $this->assertSame(['type' => 'state-picker', 'placeholder' => 'flow.response'], $array);
    }

    public function test_key_value_field_emits_labels(): void
    {
        $array = Field::keyValue('headers')
            ->label('Custom headers')
            ->keyLabel('Header')
            ->valueLabel('Value')
            ->placeholder(['Authorization' => 'Bearer ...'])
            ->toArray();

        $this->assertSame([
            'type'        => 'key-value',
            'label'       => 'Custom headers',
            'placeholder' => ['Authorization' => 'Bearer ...'],
            'key_label'   => 'Header',
            'value_label' => 'Value',
        ], $array);
    }

    public function test_object_field_emits_nested_fields_map(): void
    {
        $array = Field::object('transport_options')
            ->label('Transport options')
            ->fields([
                Field::number('retries')->default(0),
                Field::toggle('verify_ssl')->default(true)->required(),
            ])
            ->toArray();

        $this->assertSame([
            'type'     => 'object',
            'label'    => 'Transport options',
            'fields'   => [
                'retries'    => ['type' => 'number', 'default' => 0],
                'verify_ssl' => ['type' => 'boolean', 'required' => true, 'default' => true],
            ],
            'required' => ['verify_ssl'],
        ], $array);
    }

    public function test_object_array_field_emits_item_block(): void
    {
        $array = Field::objectArray('mapping')
            ->label('Mapping')
            ->itemLabel('{from} → {to}')
            ->minItems(0)
            ->maxItems(50)
            ->itemFields([
                Field::statePicker('from')->required(),
                Field::statePicker('to')->required(),
            ])
            ->toArray();

        $this->assertSame([
            'type'      => 'object-array',
            'label'     => 'Mapping',
            'item'      => [
                'fields'     => [
                    'from' => ['type' => 'state-picker', 'required' => true],
                    'to'   => ['type' => 'state-picker', 'required' => true],
                ],
                'required'   => ['from', 'to'],
                'item_label' => '{from} → {to}',
            ],
            'min_items' => 0,
            'max_items' => 50,
        ], $array);
    }

    public function test_visible_when_short_form_passes_through_unchanged(): void
    {
        $array = Field::array('success_statuses')
            ->visibleWhen(['transport_options.success_when' => 'custom'])
            ->toArray();

        $this->assertSame([
            'type'         => 'array',
            'visible_when' => ['transport_options.success_when' => 'custom'],
        ], $array);
    }

    public function test_section_meta_omits_optional_keys_when_unset(): void
    {
        $meta = Section::make('connection', 'Connection')
            ->fields([
                Field::string('url'),
                Field::number('timeout'),
            ])
            ->meta();

        $this->assertSame([
            'key'    => 'connection',
            'label'  => 'Connection',
            'fields' => ['url', 'timeout'],
        ], $meta);
    }

    public function test_section_meta_includes_icon_and_collapsed_when_set(): void
    {
        $meta = Section::make('advanced', 'Advanced')
            ->icon('cog-6-tooth')
            ->collapsed()
            ->fields([Field::keyValue('headers')])
            ->meta();

        $this->assertSame([
            'key'       => 'advanced',
            'label'     => 'Advanced',
            'icon'      => 'cog-6-tooth',
            'fields'    => ['headers'],
            'collapsed' => true,
        ], $meta);
    }

    public function test_schema_hoists_section_fields_to_top_level(): void
    {
        $array = Schema::make()
            ->section(
                Section::make('connection', 'Connection')
                    ->icon('globe-alt')
                    ->fields([
                        Field::string('url')->required()->label('URL'),
                        Field::number('timeout')->default(10),
                    ]),
            )
            ->section(
                Section::make('advanced', 'Advanced')
                    ->icon('cog-6-tooth')
                    ->collapsed()
                    ->fields([
                        Field::keyValue('headers')->label('Custom headers'),
                    ]),
            )
            ->toArray();

        $this->assertSame([
            'sections' => [
                [
                    'key'    => 'connection',
                    'label'  => 'Connection',
                    'icon'   => 'globe-alt',
                    'fields' => ['url', 'timeout'],
                ],
                [
                    'key'       => 'advanced',
                    'label'     => 'Advanced',
                    'icon'      => 'cog-6-tooth',
                    'fields'    => ['headers'],
                    'collapsed' => true,
                ],
            ],
            'url'      => ['type' => 'string', 'label' => 'URL', 'required' => true],
            'timeout'  => ['type' => 'number', 'default' => 10],
            'headers'  => ['type' => 'key-value', 'label' => 'Custom headers'],
        ], $array);
    }

    public function test_schema_emits_top_level_required_when_declared(): void
    {
        $array = Schema::make()
            ->required(['url'])
            ->fields([Field::string('url')->required()])
            ->toArray();

        $this->assertSame([
            'required' => ['url'],
            'url'      => ['type' => 'string', 'required' => true],
        ], $array);
    }

    public function test_schema_without_sections_renders_top_level_fields_only(): void
    {
        $array = Schema::make()
            ->fields([
                Field::number('seconds')->label('Delay (seconds)')->default(60),
            ])
            ->toArray();

        $this->assertSame([
            'seconds' => ['type' => 'number', 'label' => 'Delay (seconds)', 'default' => 60],
        ], $array);
    }
}
