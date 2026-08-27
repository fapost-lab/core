<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts\Schemas;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastRecipientResolver;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\ContactSegment;
use App\Filament\Support\LocalizedTextarea;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

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

            LocalizedTextarea::tabs(
                statePath: 'message',
                label: __('broadcast.fields.message'),
                helperText: __('broadcast.fields.message_help'),
                rows: 5,
            ),

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
                ->live()
                ->required(fn (Get $get): bool => BroadcastTarget::Tags->value === $get('target_type'))
                ->visible(fn (Get $get): bool => BroadcastTarget::Tags->value === $get('target_type'))
                ->options(static fn (): array => self::tagOptions()),

            Select::make('target_segment_id')
                ->label(__('broadcast.fields.segment'))
                ->searchable()
                ->live()
                ->required(fn (Get $get): bool => BroadcastTarget::Segment->value === $get('target_type'))
                ->visible(fn (Get $get): bool => BroadcastTarget::Segment->value === $get('target_type'))
                ->options(static fn (): array => ContactSegment::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),

            // Actual reach = the chosen audience intersected with THIS assistant's
            // deliverable contacts — not the segment's tenant-wide size. Segments
            // are tenant-level; a broadcast only reaches its own bot's audience.
            Placeholder::make('reach')
                ->label(__('broadcast.fields.reach'))
                ->content(static fn (Get $get): string => self::reachLabel($get)),
        ]);
    }

    private static function reachLabel(Get $get): string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Assistant) {
            return '—';
        }

        $broadcast = new Broadcast();
        $broadcast->forceFill([
            'tenant_id'         => (string) $tenant->tenant_id,
            'assistant_id'      => (string) $tenant->getKey(),
            'target_type'       => $get('target_type') ?? BroadcastTarget::All->value,
            'target_tags'       => $get('target_tags'),
            'target_segment_id' => $get('target_segment_id'),
        ]);

        try {
            $count = app(BroadcastRecipientResolver::class)->resolve($broadcast)->count();
        } catch (Throwable) {
            return '—';
        }

        return trans_choice('broadcast.reach_count', $count, ['count' => $count]);
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
