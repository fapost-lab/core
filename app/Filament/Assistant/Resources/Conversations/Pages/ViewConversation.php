<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Conversations\Pages;

use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\ConversationMessage;
use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Conversations\ConversationResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Chat-style transcript for a single thread. Renders messages as bubbles —
 * inbound (contact) left, outbound (bot/staff) right — in chronological order.
 * Also hosts the operator inbox actions: reply, takeover / return-to-bot.
 *
 * For the Postgres v1 driver this reads the {@see ConversationMessage} Eloquent
 * surface directly (documented trade-off, spec §10). When a column-store driver
 * lands, this page moves onto the reader port.
 */
final class ViewConversation extends Page
{
    /**
     * Messages are loaded newest-first up to this many, then reversed for
     * chronological display — the transcript can be arbitrarily long, so the
     * page never queries it unbounded (spec review note).
     */
    private const int MESSAGES_PAGE_SIZE = 50;

    public ?string $record = null;

    public int $visibleMessages = self::MESSAGES_PAGE_SIZE;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'filament.assistant.resources.conversations.view-conversation';

    /**
     * Per-render memoization of resolved media signed URLs — private, so it
     * lives only for the current request and needs no Livewire hydration.
     *
     * @var array<string, string|null>
     */
    private array $mediaUrlCache = [];

    /**
     * Filament auto-authorizes only its built-in resource pages; this one is a
     * custom Page, so the policy check is ours to make. The resource query
     * already scopes to the current assistant (404 for someone else's thread) —
     * this adds the permission gate on top of that scoping.
     */
    public static function canAccess(array $parameters = []): bool
    {
        return ConversationResource::canViewAny();
    }

    public function mount(string $record): void
    {
        $this->record = $record;

        $conversation = $this->resolveRecord();

        Gate::authorize('view', $conversation);

        // Must run after the authorization check above — an unauthorized
        // request must never clear the unread counter as a side effect.
        app(ConversationOwnershipInterface::class)->markRead((string) $conversation->getKey());
    }

    public function getTitle(): string
    {
        return $this->contactLabel($this->resolveRecord());
    }

    public function loadOlderMessages(): void
    {
        $this->visibleMessages += self::MESSAGES_PAGE_SIZE;
    }

    public function mediaUrl(string $mediaFileId): ?string
    {
        if (! array_key_exists($mediaFileId, $this->mediaUrlCache)) {
            $file = app(MediaServiceInterface::class)->find($mediaFileId);

            $this->mediaUrlCache[$mediaFileId] = null !== $file
                ? app(MediaServiceInterface::class)->signedUrl($file)
                : null;
        }

        return $this->mediaUrlCache[$mediaFileId];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $conversation = $this->resolveRecord();

        return [
            Action::make('reply')
                ->label(__('conversation.actions.reply'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => Gate::allows('reply', $conversation))
                ->schema([
                    Textarea::make('text')
                        ->label(__('conversation.reply.label'))
                        ->placeholder(__('conversation.reply.placeholder'))
                        ->required()
                        ->rows(4),
                ])
                ->action(fn (array $data) => $this->sendReply($conversation, (string) $data['text'])),

            Action::make('takeOver')
                ->label(__('conversation.actions.take_over'))
                ->icon(Heroicon::OutlinedHandRaised)
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('reply', $conversation) && ConversationOwner::Staff !== $conversation->owner_type)
                ->action(function () use ($conversation): void {
                    app(ConversationOwnershipInterface::class)->assign(
                        (string) $conversation->getKey(),
                        ConversationOwner::Staff,
                        (string) Auth::id(),
                    );

                    Notification::make()
                        ->success()
                        ->title(__('conversation.notifications.taken_over'))
                        ->send();
                }),

            Action::make('returnToBot')
                ->label(__('conversation.actions.return_to_bot'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('reply', $conversation) && ConversationOwner::Staff === $conversation->owner_type)
                ->action(function () use ($conversation): void {
                    app(ConversationOwnershipInterface::class)->assign(
                        (string) $conversation->getKey(),
                        ConversationOwner::Bot,
                    );

                    Notification::make()
                        ->success()
                        ->title(__('conversation.notifications.returned_to_bot'))
                        ->send();
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $conversation = $this->resolveRecord();

        return [
            'conversation'    => $conversation,
            'contactLabel'    => $this->contactLabel($conversation),
            'ownerLabel'      => $this->ownerLabel($conversation),
            'messages'        => $this->messages((string) $conversation->getKey()),
            'hasMoreMessages' => $conversation->message_count > $this->visibleMessages,
        ];
    }

    private function sendReply(Conversation $conversation, string $text): void
    {
        try {
            $result = app(ConversationReplyServiceInterface::class)->send(
                $conversation,
                $text,
                (string) Auth::id(),
            );
        } catch (ConversationReplyUndeliverableException) {
            Notification::make()
                ->danger()
                ->title(__('conversation.notifications.reply_undeliverable'))
                ->send();

            return;
        } catch (Throwable) {
            Notification::make()
                ->danger()
                ->title(__('conversation.notifications.reply_failed'))
                ->send();

            return;
        }

        $notification = Notification::make();

        if ($result->sent) {
            $notification->success()->title(__('conversation.notifications.reply_sent'));
        } else {
            $notification->danger()->title(__('conversation.notifications.reply_failed'));
        }

        $notification->send();
    }

    /**
     * @return Collection<int, ConversationMessage>
     */
    private function messages(string $conversationId): Collection
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->limit($this->visibleMessages)
            ->get()
            ->sortBy('created_at')
            ->values();
    }

    private function resolveRecord(): Conversation
    {
        // Route through the resource query so the assistant scope applies —
        // a thread of another assistant must 404, not render.
        /** @var Conversation $conversation */
        $conversation = ConversationResource::getEloquentQuery()
            ->with('contact')
            ->findOrFail($this->record);

        return $conversation;
    }

    private function contactLabel(Conversation $conversation): string
    {
        $contact = $conversation->contact;
        $meta    = is_array($contact?->meta) ? $contact->meta : [];

        $full = mb_trim((string) ($meta['first_name'] ?? '') . ' ' . (string) ($meta['last_name'] ?? ''));

        if ('' !== $full) {
            return $full;
        }

        if ('' !== (string) ($meta['username'] ?? '')) {
            return '@' . $meta['username'];
        }

        return null !== $contact ? (string) $contact->external_id : (string) $conversation->getKey();
    }

    private function ownerLabel(Conversation $conversation): string
    {
        if (ConversationOwner::Staff !== $conversation->owner_type) {
            return __('conversation.owner.bot');
        }

        $staffName = null !== $conversation->owner_staff_user_id
            ? User::query()->find($conversation->owner_staff_user_id)?->name
            : null;

        return null !== $staffName
            ? __('conversation.owner.staff_named', ['name' => $staffName])
            : __('conversation.owner.staff');
    }
}
