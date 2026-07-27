<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts\Schemas;

use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\ContactSegment;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Broadcast composer form: a name, the message body, and the audience selector.
 * The tag picker only appears for the `tags` target. Lifecycle fields (status,
 * counters, timestamps) are managed by the run, not edited here.
 */
final class BroadcastFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('broadcast.fields.name'))
                ->required()
                ->maxLength(255),

            Textarea::make('message')
                ->label(__('broadcast.fields.message'))
                ->required()
                ->rows(5)
                ->maxLength(4096)
                ->helperText(__('broadcast.fields.message_help')),

            Select::make('target_type')
                ->label(__('broadcast.fields.target'))
                ->required()
                ->live()
                ->default(BroadcastTarget::All->value)
                ->options([
                    BroadcastTarget::All->value     => __('broadcast.targets.all'),
                    BroadcastTarget::Tags->value    => __('broadcast.targets.tags'),
                    BroadcastTarget::Segment->value => __('broadcast.targets.segment'),
                ]),

            Select::make('target_tags')
                ->label(__('broadcast.fields.tags'))
                ->multiple()
                ->searchable()
                ->required(fn (Get $get): bool => BroadcastTarget::Tags->value === $get('target_type'))
                ->visible(fn (Get $get): bool => BroadcastTarget::Tags->value === $get('target_type'))
                ->options(static fn (): array => self::tagOptions()),

            Select::make('target_segment_id')
                ->label(__('broadcast.fields.segment'))
                ->searchable()
                ->required(fn (Get $get): bool => BroadcastTarget::Segment->value === $get('target_type'))
                ->visible(fn (Get $get): bool => BroadcastTarget::Segment->value === $get('target_type'))
                ->options(static fn (): array => ContactSegment::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function tagOptions(): array
    {
        $options = [];

        foreach (app(ContactTagRepositoryInterface::class)->distinctTags() as $tag) {
            $options[$tag] = $tag;
        }

        return $options;
    }
}
