<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Conversations\Pages;

use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\ConversationStatus;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\ConversationMessage;
use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Conversations\ConversationResource;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\WithFileUploads;
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
    use WithFileUploads;

    /**
     * Messages are loaded newest-first up to this many, then reversed for
     * chronological display — the transcript can be arbitrarily long, so the
     * page never queries it unbounded (spec review note).
     */
    private const int MESSAGES_PAGE_SIZE = 50;

    /** Provider limits are tighter than this; the ceiling is here to reject nonsense early. */
    private const int ATTACHMENT_MAX_KB = 20480;

    public ?string $record = null;

    public int $visibleMessages = self::MESSAGES_PAGE_SIZE;

    /**
     * Composer text. Lives on the component (not in a modal form) so the
     * operator can draft a reply while scrolling the thread.
     */
    public string $replyText = '';

    /**
     * Livewire temporary upload for the composer's attachment. Typed `mixed`
     * on purpose — the property holds a TemporaryUploadedFile mid-request and a
     * plain string (the temp path) across hydration.
     */
    public mixed $attachment = null;

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
     * Per-render memoization of staff display names — a transcript is usually a
     * handful of operators over many messages, so this keeps the name lookup to
     * one query per operator instead of one per bubble.
     *
     * @var array<string, string|null>
     */
    private array $staffNameCache = [];

    /** Same idea for the contact's display name, which every inbound bubble asks for. */
    private ?string $contactLabelCache = null;

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

    /**
     * Who wrote this message, as the transcript should name them.
     *
     * Outbound covers two very different authors — the flow engine and a human
     * operator who took the thread over — so the label (and the bubble styling
     * keyed off `sender_type` in the view) has to tell them apart.
     */
    public function authorLabel(ConversationMessage $message): string
    {
        return match ($message->sender_type) {
            MessageSenderType::Contact   => $this->contactLabelCache ??= $this->contactLabel($this->resolveRecord()),
            MessageSenderType::Assistant => __('conversation.authors.bot'),
            MessageSenderType::System    => __('conversation.authors.system'),
            MessageSenderType::Staff     => $this->staffName($message->sender_staff_user_id)
                ?? __('conversation.authors.staff'),
        };
    }

    /**
     * Whether the composer accepts input: replying is only allowed once a human
     * owns the thread, so an operator can never talk over a running flow.
     */
    public function canReplyNow(): bool
    {
        $conversation = $this->resolveRecord();

        return Gate::allows('reply', $conversation)
            && ConversationOwner::Staff === $conversation->owner_type;
    }

    public function canTakeOver(): bool
    {
        $conversation = $this->resolveRecord();

        return Gate::allows('reply', $conversation)
            && ConversationOwner::Staff !== $conversation->owner_type;
    }

    public function takeOverThread(): void
    {
        $conversation = $this->resolveRecord();

        Gate::authorize('reply', $conversation);

        app(ConversationOwnershipInterface::class)->assign(
            (string) $conversation->getKey(),
            ConversationOwner::Staff,
            (string) Auth::id(),
        );

        Notification::make()
            ->success()
            ->title(__('conversation.notifications.taken_over'))
            ->send();
    }

    public function returnThreadToBot(): void
    {
        $conversation = $this->resolveRecord();

        Gate::authorize('reply', $conversation);

        app(ConversationOwnershipInterface::class)->assign(
            (string) $conversation->getKey(),
            ConversationOwner::Bot,
        );

        $this->replyText = '';

        Notification::make()
            ->success()
            ->title(__('conversation.notifications.returned_to_bot'))
            ->send();
    }

    /**
     * Send what the composer holds. The ownership check is repeated here rather
     * than trusted from the disabled input — a disabled textarea is a hint to
     * the operator, not a guarantee to the server.
     */
    public function sendComposerReply(): void
    {
        $conversation = $this->resolveRecord();

        Gate::authorize('reply', $conversation);

        if (ConversationOwner::Staff !== $conversation->owner_type) {
            Notification::make()
                ->danger()
                ->title(__('conversation.notifications.reply_requires_takeover'))
                ->send();

            return;
        }

        // A bare attachment is a valid message, so the text is only required
        // when nothing is attached.
        $this->validate(
            [
                'replyText'  => [null === $this->attachment ? 'required' : 'nullable', 'string', 'max:4096'],
                'attachment' => ['nullable', 'file', 'max:' . self::ATTACHMENT_MAX_KB],
            ],
            [
                'replyText.required' => __('conversation.reply.required'),
                'attachment.max'     => __('conversation.reply.attachment_too_large'),
            ],
        );

        $mediaFileId = null !== $this->attachment
            ? (string) $this->storeAttachment()->getKey()
            : null;

        $this->deliverReply($conversation, mb_trim($this->replyText), $mediaFileId);

        $this->replyText  = '';
        $this->attachment = null;

        $this->dispatch('conversation-updated');
    }

    /**
     * Close the thread when it is open, reopen it when it is not — the inbox
     * needs a way to mark work finished, and a single toggle is enough while
     * `snoozed` has no UI of its own.
     */
    public function toggleStatus(): void
    {
        $conversation = $this->resolveRecord();

        Gate::authorize('reply', $conversation);

        $next = ConversationStatus::Open === $conversation->status
            ? ConversationStatus::Closed
            : ConversationStatus::Open;

        $conversation->forceFill(['status' => $next])->save();

        Notification::make()
            ->success()
            ->title(__('conversation.notifications.status_changed', [
                'status' => __('conversation.statuses.' . $next->value),
            ]))
            ->send();
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
     * Every control lives in the composer under the transcript — replying,
     * taking the thread over, handing it back. An operator works at the bottom
     * of the thread, and a header button means travelling back up for it.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
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
            'messages'        => $this->transcriptMessages((string) $conversation->getKey()),
            'hasMoreMessages' => $conversation->message_count > $this->visibleMessages,
        ];
    }

    private function deliverReply(Conversation $conversation, string $text, ?string $mediaFileId = null): void
    {
        try {
            $result = app(ConversationReplyServiceInterface::class)->send(
                $conversation,
                $text,
                (string) Auth::id(),
                $mediaFileId,
            );
        } catch (ConversationReplyUndeliverableException) {
            Notification::make()
                ->danger()
                ->title(__('conversation.notifications.reply_undeliverable'))
                ->send();

            return;
        } catch (VolumeLimitReachedException $exception) {
            Notification::make()
                ->danger()
                ->title(__('conversation.notifications.reply_limit_reached'))
                ->body($exception->getMessage())
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
    private function transcriptMessages(string $conversationId): Collection
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

    /**
     * Park the composer's upload in the tenant media library before sending —
     * outbound media is addressed by media file id everywhere, and this also
     * leaves the operator's attachment in the library like any other file.
     */
    private function storeAttachment(): MediaFile
    {
        /** @var \Illuminate\Http\UploadedFile $upload */
        $upload = $this->attachment;

        return app(MediaUploaderInterface::class)->uploadFromUploadedFile(
            file: $upload,
            folder: app(MediaServiceInterface::class)->findOrCreateInboxFolder(),
            name: $upload->getClientOriginalName(),
            source: MediaSource::Upload,
            uploadedBy: (string) Auth::id(),
        );
    }

    private function staffName(?string $staffUserId): ?string
    {
        if (null === $staffUserId) {
            return null;
        }

        if (! array_key_exists($staffUserId, $this->staffNameCache)) {
            $this->staffNameCache[$staffUserId] = User::query()->find($staffUserId)?->name;
        }

        return $this->staffNameCache[$staffUserId];
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
