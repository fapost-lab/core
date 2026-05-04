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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Str;

final class FlowsTable
{
    // ── Heroicon SVG paths (outline, 1.5 stroke) ──────────────────────────────

    /** ⌨  command-line: keyword / message trigger */
    private const string ICON_MESSAGE = 'M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0021 18V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v12a2.25 2.25 0 002.25 2.25z';

    /** 🕐  clock: schedule trigger */
    private const string ICON_SCHEDULE = 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z';

    /** ⬇  arrow-down-tray: webhook trigger */
    private const string ICON_WEBHOOK = 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3';

    /** ⚡  bolt: event trigger */
    private const string ICON_EVENT = 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z';

    /** </>  code-bracket: api trigger */
    private const string ICON_API = 'M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5';

    // ─────────────────────────────────────────────────────────────────────────

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('is_active')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-s-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->width('1.75rem'),

                TextColumn::make('name')
                    ->html()
                    ->formatStateUsing(function (string $state, FlowDraft $record): string {
                        $name = '<span class="flow-name">' . e($state) . '</span>';
                        $hint = self::triggerHint($record->trigger);

                        return null !== $hint
                            ? $name . $hint
                            : $name;
                    })
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

    // ── Trigger hint HTML ─────────────────────────────────────────────────────

    public static function triggerHint(?FlowTrigger $trigger): ?string
    {
        if (null === $trigger) {
            return null;
        }

        /** @var array<string, mixed> $config */
        $config = is_array($trigger->config) ? $trigger->config : [];

        return match ($trigger->type->value) {
            'message'  => self::messageHint($config),
            'schedule' => self::scheduleHint($config),
            'webhook'  => self::webhookHint($config),
            'event'    => self::eventHint($config),
            'api'      => self::apiHint($config),
            default    => null,
        };
    }

    /** @param array<string, mixed> $config */
    private static function messageHint(array $config): ?string
    {
        $all = array_merge(
            self::collectItems($config['keywords'] ?? []),
            self::collectItems($config['phrases'] ?? []),
        );

        if ([] === $all) {
            return null;
        }

        $text = implode(', ', array_slice($all, 0, 6));

        if (count($all) > 6) {
            $text .= ' +' . (count($all) - 6);
        }

        return self::hint(self::ICON_MESSAGE, Str::limit($text, 80, '…'));
    }

    /** @param array<string, mixed> $config */
    private static function scheduleHint(array $config): ?string
    {
        $cron = self::nonEmpty($config['cron'] ?? null);

        if (null === $cron) {
            return null;
        }

        return self::hint(self::ICON_SCHEDULE, $cron);
    }

    /** @param array<string, mixed> $config */
    private static function webhookHint(array $config): ?string
    {
        $method = self::nonEmpty($config['method'] ?? null);
        $path   = self::nonEmpty($config['path'] ?? null);

        if (null === $method && null === $path) {
            return null;
        }

        return self::hint(self::ICON_WEBHOOK, mb_trim(($method ?? '') . ' ' . ($path ?? '')));
    }

    /** @param array<string, mixed> $config */
    private static function eventHint(array $config): ?string
    {
        $name = self::nonEmpty($config['event_name'] ?? null);

        if (null === $name) {
            return null;
        }

        return self::hint(self::ICON_EVENT, $name);
    }

    /** @param array<string, mixed> $config */
    private static function apiHint(array $config): ?string
    {
        $key = self::nonEmpty($config['route_key'] ?? null);

        if (null === $key) {
            return null;
        }

        return self::hint(self::ICON_API, $key);
    }

    // ── Render helpers ────────────────────────────────────────────────────────

    private static function hint(string $iconPath, string $text): string
    {
        $svg = '<svg class="fth-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"'
            . ' stroke-width="1.5" stroke="currentColor">'
            . '<path stroke-linecap="round" stroke-linejoin="round" d="' . $iconPath . '"/>'
            . '</svg>';

        return '<div class="fth">' . $svg . '<span>' . e($text) . '</span></div>';
    }

    // ── Data helpers ──────────────────────────────────────────────────────────

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private static function collectItems(mixed $values): array
    {
        if ( ! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $v): ?string => is_string($v) && '' !== mb_trim($v)
                    ? mb_trim($v)
                    : null,
                $values,
            ),
        ));
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && '' !== mb_trim($value) ? mb_trim($value) : null;
    }
}
