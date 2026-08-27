<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Conversations;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Conversations\Pages\ListConversations;
use App\Filament\Assistant\Resources\Conversations\Pages\ViewConversation;
use App\Filament\Assistant\Resources\Conversations\Tables\ConversationsTable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Read-only assistant-scoped conversation transcript viewer. Lists threads with
 * inbox-style denormalized signals (last preview, recency, unread) and opens a
 * chat-style transcript per thread. Read only — operators cannot mutate the log;
 * writes come from the runtime capture sites.
 */
final class ConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static ?int $navigationSort = 75;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    /**
     * `conversations` has a direct `assistant_id` column but the model has no
     * Filament tenancy relation, so we disable auto-scope and filter manually.
     */
    protected static bool $isScopedToTenant = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('conversation.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('conversation.plural_label');
    }

    public static function table(Table $table): Table
    {
        return ConversationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view'  => ViewConversation::route('/{record}'),
        ];
    }

    /**
     * Gated by {@see \App\Domains\Conversation\Policies\ConversationPolicy} —
     * transcripts hold every message a contact ever sent, so being signed in is
     * not enough. Filament resolves canViewAny() through the policy; this only
     * keeps the nav item in step with it.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user() instanceof User && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Count of threads with unread messages in the current assistant scope.
     * Reuses {@see getEloquentQuery()} so the count follows the exact same
     * tenant/assistant scoping as the list itself.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::shouldRegisterNavigation()) {
            return null;
        }

        $count = static::getEloquentQuery()->where('unread_count', '>', 0)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Restrict to threads owned by the current Filament-resolved Assistant. The
     * tenant Postgres schema already isolates by tenant; this adds per-assistant
     * scoping within the schema.
     */
    public static function getEloquentQuery(): Builder
    {
        $query  = parent::getEloquentQuery();
        $tenant = Filament::getTenant();

        if ($tenant instanceof Assistant) {
            $query->where('assistant_id', $tenant->getKey());
        }

        return $query;
    }
}
