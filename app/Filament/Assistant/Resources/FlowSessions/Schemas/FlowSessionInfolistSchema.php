<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowSessions\Schemas;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use BackedEnum;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Diagnostic infolist for a single FlowSession. Three sections:
 *  - Identity (ids, status, end_status, current_node_id, version, age)
 *  - Lineage (parent_session_id, parent_resume_node_id, expires_at)
 *  - State (flat key/value of the JSON state — namespaced flow.* / system.* / rag.*)
 */
final class FlowSessionInfolistSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('assistant.flow_sessions.fields.id'))
                ->columns(3)
                ->components([
                    TextEntry::make('id')->copyable()->fontFamily('mono'),
                    TextEntry::make('status')
                        ->label(__('assistant.flow_sessions.fields.status'))
                        ->badge()
                        ->formatStateUsing(static fn (FlowSessionStatus $state): string => __('assistant.flow_sessions.statuses.' . $state->value)),
                    TextEntry::make('end_status')
                        ->label(__('assistant.flow_sessions.fields.end_status'))
                        ->placeholder('—'),
                    TextEntry::make('current_node_id')
                        ->label(__('assistant.flow_sessions.fields.current_node_id'))
                        ->placeholder('—')
                        ->fontFamily('mono'),
                    TextEntry::make('version')
                        ->label(__('assistant.flow_sessions.fields.version')),
                    TextEntry::make('flowDefinition.name')
                        ->label(__('assistant.flow_sessions.fields.flow'))
                        ->placeholder('—'),
                    TextEntry::make('contact.external_id')
                        ->label(__('assistant.flow_sessions.fields.contact'))
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label(__('assistant.flow_sessions.fields.created_at'))
                        ->dateTime()
                        ->since(),
                    TextEntry::make('updated_at')
                        ->label(__('assistant.flow_sessions.fields.updated_at'))
                        ->dateTime()
                        ->since(),
                ]),

            Section::make(__('assistant.flow_sessions.fields.parent'))
                ->columns(3)
                ->visible(static fn ($record): bool => null !== ($record->parent_session_id ?? null))
                ->components([
                    TextEntry::make('parent_session_id')
                        ->copyable()
                        ->fontFamily('mono')
                        ->placeholder('—'),
                    TextEntry::make('parent_resume_node_id')
                        ->fontFamily('mono')
                        ->placeholder('—'),
                    TextEntry::make('expires_at')
                        ->label(__('assistant.flow_sessions.fields.expires_at'))
                        ->dateTime()
                        ->since()
                        ->placeholder('—'),
                ]),

            Section::make(__('assistant.flow_sessions.fields.state'))
                ->collapsed(false)
                ->components([
                    KeyValueEntry::make('state')
                        ->state(static fn ($record): array => self::flattenState(is_array($record->state ?? null) ? $record->state : [])),
                ]),

            // Opt-in audit trail. Only visible if logging_enabled was set on
            // the running definition or if rows already exist (back-compat for
            // sessions whose flag changed mid-flight).
            Section::make(__('assistant.flow_sessions.fields.history'))
                ->collapsed()
                ->visible(static fn (FlowSession $record): bool => self::hasHistory($record))
                ->components([
                    RepeatableEntry::make('historyEntries')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('created_at')
                                ->label(__('assistant.flow_sessions.history.timestamp'))
                                ->dateTime()
                                ->since(),
                            TextEntry::make('node_id')
                                ->label(__('assistant.flow_sessions.history.node'))
                                ->fontFamily('mono'),
                            TextEntry::make('event_type')
                                ->label(__('assistant.flow_sessions.history.event'))
                                ->badge()
                                ->formatStateUsing(static fn ($state): string => $state instanceof BackedEnum ? (string) $state->value : (string) $state),
                            TextEntry::make('path')
                                ->label(__('assistant.flow_sessions.history.path'))
                                ->placeholder('—')
                                ->fontFamily('mono'),
                            TextEntry::make('payload')
                                ->columnSpanFull()
                                ->label(__('assistant.flow_sessions.history.payload'))
                                ->state(static fn ($record): string => self::renderPayload($record)),
                        ]),
                ]),
        ]);
    }

    private static function hasHistory(FlowSession $session): bool
    {
        $definition = $session->flowDefinition;
        if (null !== $definition && (bool) ($definition->logging_enabled ?? false)) {
            return true;
        }

        return $session->historyEntries()->exists();
    }

    /**
     * Compact human-friendly representation of an audit row payload — old/new
     * values plus structured metadata, JSON-encoded for predictable display.
     */
    private static function renderPayload(mixed $record): string
    {
        $parts = [];

        if (null !== $record->old_value) {
            $parts[] = 'old: ' . self::stringify($record->old_value);
        }
        if (null !== $record->new_value) {
            $parts[] = 'new: ' . self::stringify($record->new_value);
        }
        if (is_array($record->metadata) && [] !== $record->metadata) {
            $parts[] = 'meta: ' . self::stringify($record->metadata);
        }

        return [] === $parts ? '—' : implode(' · ', $parts);
    }

    /**
     * Flatten the namespaced session JSON for compact display: nested arrays
     * become {@code namespace.path → json-encoded leaf} so ops can scan a
     * dozen-key state without expanding nodes manually.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    private static function flattenState(array $state, string $prefix = ''): array
    {
        $flat = [];

        foreach ($state as $key => $value) {
            $path = '' === $prefix ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && [] !== $value && ! self::isList($value)) {
                $flat = [...$flat, ...self::flattenState($value, $path)];
                continue;
            }

            $flat[$path] = self::stringify($value);
        }

        return $flat;
    }

    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || null === $value) {
            return var_export($value, true);
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<int|string, mixed>  $value
     */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
