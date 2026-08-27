<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments\Schemas;

use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentMatch;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Segment rule builder: a name, an all/any combinator, and a repeater of
 * conditions. Each condition filters on a contact attribute (tag / language /
 * platform / group). The composite is folded into the model's `rules` json by
 * the create/edit pages, which also fold the group condition's dedicated
 * `value_group` field back into the single `value` key the resolver expects
 * (see {@see \App\Filament\Assistant\Resources\ContactSegments\Pages\CreateContactSegment}).
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
                ->columns(4)
                ->schema([
                    Select::make('type')
                        ->label(__('segment.condition.type'))
                        ->required()
                        ->live()
                        ->default(SegmentConditionType::Tag->value)
                        ->options([
                            SegmentConditionType::Tag->value       => __('segment.condition.types.tag'),
                            SegmentConditionType::Language->value  => __('segment.condition.types.language'),
                            SegmentConditionType::Platform->value  => __('segment.condition.types.platform'),
                            SegmentConditionType::Attribute->value => __('segment.condition.types.attribute'),
                            SegmentConditionType::Group->value     => __('segment.condition.types.group'),
                        ]),

                    TextInput::make('key')
                        ->label(__('segment.condition.key'))
                        ->placeholder('profile.city')
                        ->required(fn (Get $get): bool => SegmentConditionType::Attribute->value === $get('type'))
                        ->visible(fn (Get $get): bool => SegmentConditionType::Attribute->value === $get('type')),

                    Select::make('operator')
                        ->label(__('segment.condition.operator'))
                        ->required()
                        ->live()
                        ->options(static fn (Get $get): array => self::operatorOptions((string) $get('type'))),

                    TagsInput::make('value')
                        ->label(__('segment.condition.value'))
                        ->required(fn (Get $get): bool => self::usesFreeformValue($get))
                        ->visible(fn (Get $get): bool => self::usesFreeformValue($get))
                        ->helperText(__('segment.condition.value_help')),

                    Select::make('value_group')
                        ->label(__('segment.condition.value'))
                        ->multiple()
                        ->searchable()
                        ->options(static fn (): array => self::groupOptions())
                        ->required(fn (Get $get): bool => SegmentConditionType::Group->value === $get('type'))
                        ->visible(fn (Get $get): bool => SegmentConditionType::Group->value === $get('type')),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function operatorOptions(string $type): array
    {
        return match (SegmentConditionType::tryFrom($type)) {
            SegmentConditionType::Tag => [
                'has'     => __('segment.operators.has'),
                'not_has' => __('segment.operators.not_has'),
            ],
            SegmentConditionType::Attribute => [
                'eq'     => __('segment.operators.eq'),
                'ne'     => __('segment.operators.ne'),
                'exists' => __('segment.operators.exists'),
            ],
            SegmentConditionType::Group => [
                'in'     => __('segment.operators.in'),
                'not_in' => __('segment.operators.not_in'),
            ],
            default => [
                'in' => __('segment.operators.in'),
                'eq' => __('segment.operators.eq'),
            ],
        };
    }

    /**
     * The freeform {@see TagsInput} value field applies to every condition
     * type except `group` (dedicated {@see Select}) and the `exists` operator
     * (no value needed).
     */
    private static function usesFreeformValue(Get $get): bool
    {
        return SegmentConditionType::Group->value !== $get('type') && 'exists' !== $get('operator');
    }

    /**
     * @return array<string, string>
     */
    private static function groupOptions(): array
    {
        return ContactGroup::query()
            ->where('tenant_id', app(TenantContextInterface::class)->get()->getId())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
