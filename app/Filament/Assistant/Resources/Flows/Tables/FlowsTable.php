<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Flows\Tables;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Str;

final class FlowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (FlowDraft $record): ?string => self::summarizeTrigger($record->trigger))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('group.name')
                    ->label(__('assistant.flows.fields.group'))
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('is_public')
                    ->label(__('assistant.flows.fields.is_public'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('assistant.flows.visibility.public')
                        : __('assistant.flows.visibility.private'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                TextColumn::make('published_version')
                    ->label(__('assistant.flows.fields.versions'))
                    ->getStateUsing(fn (FlowDraft $record): string => null !== $record->published_version
                        ? 'v' . (int)$record->published_version
                        : '—'),
            ])
            ->recordClasses(fn (FlowDraft $record): string => $record->is_active
                ? 'flow-row-active'
                : 'flow-row-inactive')
            ->groups([
                Group::make('flow_group_id')
                    ->label(__('assistant.flows.fields.group'))
                    ->getTitleFromRecordUsing(
                        fn (FlowDraft $record): string => $record->group?->name ?? __('assistant.flows.groups.ungrouped')
                    )
                    ->collapsible(),
            ])
            ->filters([
                SelectFilter::make('flow_group_id')
                    ->relationship('group', 'name')
                    ->label(__('assistant.flows.filters.group')),

                TernaryFilter::make('is_active')
                    ->label(__('assistant.flows.filters.is_active')),
            ])
            ->recordActions([
                Action::make('open_builder')
                    ->label(__('assistant.flows.actions.open_builder'))
                    ->icon(Heroicon::PencilSquare)
                    ->url(fn (FlowDraft $record): string => url("/builder/flows/{$record->flow_id}"))
                    ->openUrlInNewTab(),

                ActionGroup::make([
                    EditAction::make(),
                    Action::make('toggle_active')
                        ->label(fn (FlowDraft $record): string => $record->is_active
                            ? __('assistant.flows.actions.deactivate')
                            : __('assistant.flows.actions.activate'))
                        ->icon(fn (FlowDraft $record): Heroicon => $record->is_active ? Heroicon::Pause : Heroicon::Play)
                        ->action(fn (FlowDraft $record) => $record->update(['is_active' => ! $record->is_active]))
                        ->requiresConfirmation(),
                    DeleteAction::make()
                        ->before(function (FlowDraft $record, DeleteAction $action): void {
                            $hasSessions = FlowSession::where('flow_id', $record->flow_id)
                                ->whereIn('status', [
                                    FlowSessionStatus::Active->value,
                                    FlowSessionStatus::WaitingInput->value,
                                    FlowSessionStatus::Paused->value,
                                ])
                                ->exists();

                            if ($hasSessions) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('assistant.flows.delete_guard.title'))
                                    ->body(__('assistant.flows.delete_guard.body'))
                                    ->send();

                                $action->halt();
                            }
                        }),
                ])
                    ->icon(Heroicon::EllipsisVertical)
                    ->iconButton(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function summarizeTrigger(?FlowTrigger $trigger): ?string
    {
        if (null === $trigger) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = is_array($trigger->config) ? $trigger->config : [];

        return match ($trigger->type->value) {
            'message' => __('assistant.flows.trigger_summary.message', [
                'keywords' => self::summarizeList($config['keywords'] ?? []),
                'phrases'  => self::summarizeList($config['phrases'] ?? []),
            ]),
            'event' => __('assistant.flows.trigger_summary.event', [
                'event' => self::stringOrDash($config['event_name'] ?? null),
            ]),
            'schedule' => __('assistant.flows.trigger_summary.schedule', [
                'cron'     => self::stringOrDash($config['cron'] ?? null),
                'timezone' => self::stringOrDash($config['timezone'] ?? null),
            ]),
            'webhook' => __('assistant.flows.trigger_summary.webhook', [
                'method' => self::stringOrDash($config['method'] ?? null),
                'path'   => self::stringOrDash($config['path'] ?? null),
            ]),
            'api' => __('assistant.flows.trigger_summary.api', [
                'route_key'       => self::stringOrDash($config['route_key'] ?? null),
                'allowed_sources' => self::summarizeList($config['allowed_sources'] ?? []),
            ]),
            default => __('assistant.flows.trigger_summary.unknown'),
        };
    }

    /**
     * @param  mixed  $values
     */
    private static function summarizeList(mixed $values): string
    {
        if ( ! is_array($values)) {
            return '—';
        }

        $items = array_values(
            array_filter(
                array_map(
                    static fn (mixed $value): ?string => is_string($value) && '' !== mb_trim($value) ? mb_trim(
                        $value
                    ) : null,
                    $values,
                )
            )
        );

        if ([] === $items) {
            return '—';
        }

        $visible = array_slice($items, 0, 3);
        $summary = implode(', ', $visible);

        if (count($items) > 3) {
            $summary .= ' +' . (count($items) - 3);
        }

        return Str::limit($summary, 80);
    }

    /**
     * @param  mixed  $value
     */
    private static function stringOrDash(mixed $value): string
    {
        return is_string($value) && '' !== mb_trim($value) ? mb_trim($value) : '—';
    }
}
