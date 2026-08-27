<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Conversations\Tables;

use App\Domains\Conversation\Enums\ConversationStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Inbox-style thread list. Every column reads from the denormalized aggregate —
 * no message scan needed. The contact label is derived from the linked Contact's
 * `meta` JSON (there is no `name` column), mirroring the ContactsTable convention.
 */
final class ConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                TextColumn::make('contact')
                    ->label(__('conversation.fields.contact'))
                    ->state(static fn ($record): string => self::contactLabel($record))
                    ->searchable(query: static fn ($query, string $search) => $query->whereHas(
                        'contact',
                        static fn ($q) => $q->where('external_id', 'like', "%{$search}%"),
                    ))
                    ->weight('medium')
                    ->limit(32),

                TextColumn::make('platform')
                    ->label(__('conversation.fields.platform'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => ucfirst($state)),

                TextColumn::make('last_message_preview')
                    ->label(__('conversation.fields.last_message'))
                    ->placeholder('—')
                    ->limit(48)
                    ->color('gray'),

                TextColumn::make('unread_count')
                    ->label(__('conversation.fields.unread'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(static fn (int $state): string => (string) $state),

                TextColumn::make('message_count')
                    ->label(__('conversation.fields.messages'))
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label(__('conversation.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (ConversationStatus $state): string => __('conversation.statuses.' . $state->value))
                    ->color(static fn (ConversationStatus $state): string => match ($state) {
                        ConversationStatus::Open    => 'success',
                        ConversationStatus::Snoozed => 'warning',
                        ConversationStatus::Closed  => 'gray',
                    }),

                TextColumn::make('last_message_at')
                    ->label(__('conversation.fields.last_activity'))
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('conversation.fields.status'))
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->paginated([25, 50, 100])
            ->poll('30s');
    }

    private static function contactLabel(object $record): string
    {
        $contact = $record->contact ?? null;
        $meta    = is_object($contact) && is_array($contact->meta ?? null) ? $contact->meta : [];

        $full = mb_trim((string) ($meta['first_name'] ?? '') . ' ' . (string) ($meta['last_name'] ?? ''));

        if ('' !== $full) {
            return $full;
        }

        if ('' !== (string) ($meta['username'] ?? '')) {
            return '@' . $meta['username'];
        }

        return is_object($contact) ? (string) $contact->external_id : '—';
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (ConversationStatus::cases() as $case) {
            $options[$case->value] = __('conversation.statuses.' . $case->value);
        }

        return $options;
    }
}
