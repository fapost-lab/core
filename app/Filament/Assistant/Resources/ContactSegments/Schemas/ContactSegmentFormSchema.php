<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments\Schemas;

use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentMatch;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Segment rule builder: a name, an all/any combinator, and a repeater of
 * conditions. Each condition filters on a contact attribute (tag / language /
 * platform). The composite is folded into the model's `rules` json by the
 * create/edit pages.
 */
final class ContactSegmentFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('segment.fields.name'))
                ->required()
                ->maxLength(255),

            Select::make('match')
                ->label(__('segment.fields.match'))
                ->required()
                ->default(SegmentMatch::All->value)
                ->options([
                    SegmentMatch::All->value => __('segment.match.all'),
                    SegmentMatch::Any->value => __('segment.match.any'),
                ]),

            Repeater::make('conditions')
                ->label(__('segment.fields.conditions'))
                ->addActionLabel(__('segment.actions.add_condition'))
                ->default([])
                ->columns(3)
                ->schema([
                    Select::make('type')
                        ->label(__('segment.condition.type'))
                        ->required()
                        ->live()
                        ->default(SegmentConditionType::Tag->value)
                        ->options([
                            SegmentConditionType::Tag->value      => __('segment.condition.types.tag'),
                            SegmentConditionType::Language->value => __('segment.condition.types.language'),
                            SegmentConditionType::Platform->value => __('segment.condition.types.platform'),
                        ]),

                    Select::make('operator')
                        ->label(__('segment.condition.operator'))
                        ->required()
                        ->options(static fn (Get $get): array => self::operatorOptions((string) $get('type'))),

                    TagsInput::make('value')
                        ->label(__('segment.condition.value'))
                        ->required()
                        ->helperText(__('segment.condition.value_help')),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function operatorOptions(string $type): array
    {
        if (SegmentConditionType::Tag->value === $type) {
            return [
                'has'     => __('segment.operators.has'),
                'not_has' => __('segment.operators.not_has'),
            ];
        }

        return [
            'in' => __('segment.operators.in'),
            'eq' => __('segment.operators.eq'),
        ];
    }
}
