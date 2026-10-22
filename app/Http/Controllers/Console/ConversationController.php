<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Enums\ReplyOutcome;
use App\Domains\Conversation\Exceptions\ConversationHeldByAnotherOperatorException;
use App\Domains\Conversation\Exceptions\ConversationNotHeldByStaffException;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Exceptions\ConversationTransferTargetIneligibleException;
use App\Domains\Conversation\Live\ConversationActivityChannel;
use App\Domains\Conversation\Live\ConversationActivityWatchers;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\ConversationMessage;
use App\Domains\Conversation\Services\ConversationInbox;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Services\StorageLimitMessage;
use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\ReplyConversationRequest;
use App\Http\Requests\Console\SeizeConversationRequest;
use App\Http\Requests\Console\TransferConversationRequest;
use Fapost\Foundation\Console\Enums\TableTarget;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The conversation inbox on the Inertia console: the assistant's threads, and one thread's transcript with the
 * operator's controls (take over, reply, finish, transfer, seize), replacing the Filament conversation resource.
 *
 * Reading needs `view` on conversations; every write needs `reply`, and the service then decides whether this operator
 * holds the thread (an administrator seizes another operator's thread first, the one write that also needs the
 * administrator role). Opening a thread clears its unread counter only
 * after `view` is authorized and only on a full visit, as the Filament page did on mount: a background reload of the
 * open page does not mark what arrived meanwhile as read. A reply reaches a real person, so it goes through
 * {@see ConversationInbox::reply()}, which sends one message per submission. Both pages stay current through the
 * assistant's live conversation channel ({@see ConversationActivityChannel}), or by polling.
 */
final class ConversationController extends Controller
{
    /** Messages a thread page shows at first, and how many more each "load older" adds. */
    public const int MESSAGES_PAGE = 50;

    /** The most a thread page shows: older history is the transcript's, not the screen's. */
    public const int MESSAGES_MAX = 1000;

    public function __construct(
        private readonly ConversationInbox $inbox,
        private readonly CurrentAssistantInterface $assistant,
        private readonly ConversationActivityWatchers $watchers,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Conversation::class);

        $assistant = $this->assistant->get();
        $names     = [];
        $table     = new DataTable(
            sortable: ['last_message_at'],
            searchable: [],
            defaultSort: '-last_message_at',
            filters: [
                'handling' => fn (Builder $query, string $value): bool => $this->inbox->filterHandling($query, $value, $this->userId($request)),
            ],
            // Case-insensitive on both engines, escaped as DataTable::applySearch escapes its columns.
            searchAlso: static fn (Builder $query, string $search) => $query->orWhereHas(
                'contact',
                static fn (Builder $contact) => $contact->whereRaw(
                    'LOWER(' . $contact->getQuery()->getGrammar()->wrap('contacts.external_id') . ") LIKE ? ESCAPE '!'",
                    ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%'],
                ),
            ),
        );

        return Inertia::render('Console/Conversations/Index', [
            'table' => $table->extend(TableTarget::Conversations)->respond(
                $request,
                $this->inbox->query($assistant)->with('contact'),
                function (Conversation $conversation) use ($assistant, $request, &$names): array {
                    $owner   = $this->owner($conversation);
                    $ownerId = ConversationOwner::Staff === $owner ? $conversation->owner_staff_user_id : null;

                    return [
                        'id'            => (string) $conversation->getKey(),
                        'contact'       => $this->inbox->contactLabel($conversation),
                        'platform'      => (string) $conversation->platform,
                        'preview'       => $conversation->last_message_preview,
                        'unread'        => (int) $conversation->unread_count,
                        'messages'      => (int) $conversation->message_count,
                        'owner'         => $owner->value,
                        'ownerName'     => null !== $ownerId ? $this->ownerName($ownerId, $names) : null,
                        'queuedAt'      => ConversationOwner::Queued === $owner ? $conversation->queued_at?->toIso8601String() : null,
                        'mine'          => null !== $ownerId && $ownerId === $this->userId($request),
                        'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
                        'viewUrl'       => $this->url('show', $assistant, ['record' => $conversation->getKey()]),
                    ];
                },
            ),
            'handlings' => ['queued', 'mine', 'staff', 'bot'],
            'live'      => $this->live($assistant),
            'urls'      => ['index' => $this->url('index', $assistant)],
        ]);
    }

    /*
     * Route parameters reach an action by position, so `$assistantKey` (the assistant in the URL, already resolved by the
     * console stack) is declared ahead of `$record`.
     */
    public function show(Request $request, string $assistantKey, string $record): Response
    {
        $assistant    = $this->assistant->get();
        $conversation = $this->inbox->find($assistant, $record);

        Gate::authorize('view', $conversation);

        // After the authorization above: an unauthorized request never clears the counter as a side effect.
        if (! $request->hasHeader('X-Inertia-Partial-Component')) {
            $this->inbox->markRead($conversation);
        }

        $limit    = $this->messageLimit($request);
        $messages = $this->inbox->transcript($conversation, $limit);
        $owner    = $this->owner($conversation);
        $ownerId  = ConversationOwner::Staff === $owner && null !== $conversation->owner_staff_user_id ? (string) $conversation->owner_staff_user_id : null;
        $me       = $this->userId($request);
        $canReply = Gate::allows('reply', $conversation);
        $holding  = $canReply && null !== $ownerId && $ownerId === $me;
        $key      = (string) $conversation->getKey();
        $names    = [];
        $user     = $request->user();

        return Inertia::render('Console/Conversations/Show', [
            'conversation' => [
                'id'           => $key,
                'contact'      => $this->inbox->contactLabel($conversation),
                'platform'     => (string) $conversation->platform,
                'owner'        => $owner->value,
                'ownerId'      => $ownerId,
                'ownerName'    => null !== $ownerId ? $this->ownerName($ownerId, $names) : null,
                'queuedAt'     => ConversationOwner::Queued === $owner ? $conversation->queued_at?->toIso8601String() : null,
                'messageCount' => (int) $conversation->message_count,
            ],
            'messages' => $this->messages($conversation, $messages),
            'paging'   => [
                'limit'   => $limit,
                'hasMore' => $conversation->message_count > $messages->count() && $limit < self::MESSAGES_MAX,
                'older'   => min($limit + self::MESSAGES_PAGE, self::MESSAGES_MAX),
            ],
            'can' => [
                'reply'    => $canReply,
                'compose'  => $holding,
                'takeOver' => $canReply && ConversationOwner::Staff !== $owner,
                'finish'   => $holding,
                'transfer' => $holding,
                'seize'    => $user instanceof User && $user->isAdmin() && null !== $ownerId && $ownerId !== $me,
            ],
            'transferTargets' => $holding && $user instanceof User ? $this->inbox->transferTargets($assistant, $user) : [],
            'attachment'      => [
                'maxKb'  => ReplyConversationRequest::attachmentMaxKb(),
                'accept' => implode(',', ReplyConversationRequest::allowedMimeTypes()),
            ],
            'textMax' => ReplyConversationRequest::TEXT_MAX,
            'live'    => $this->live($assistant),
            'urls'    => [
                'index'    => $this->url('index', $assistant),
                'self'     => $this->url('show', $assistant, ['record' => $key]),
                'reply'    => $this->url('reply', $assistant, ['record' => $key]),
                'takeOver' => $this->url('takeover', $assistant, ['record' => $key]),
                'finish'   => $this->url('finish', $assistant, ['record' => $key]),
                'transfer' => $this->url('transfer', $assistant, ['record' => $key]),
                'seize'    => $this->url('seize', $assistant, ['record' => $key]),
            ],
        ]);
    }

    public function reply(ReplyConversationRequest $request, string $assistantKey, string $record): RedirectResponse
    {
        $assistant    = $this->assistant->get();
        $conversation = $this->inbox->find($assistant, $record);

        try {
            $outcome = $this->inbox->reply(
                $conversation,
                $this->userId($request),
                $request->requestId(),
                $request->text(),
                $request->attachment(),
            );
        } catch (ConversationNotHeldByStaffException) {
            return $this->back(trans('conversation.notifications.reply_requires_takeover'), 'error');
        } catch (ConversationHeldByAnotherOperatorException) {
            return $this->back(trans('conversation.notifications.reply_held_by_another'), 'error');
        } catch (ConversationReplyUndeliverableException) {
            return $this->back(trans('conversation.notifications.reply_undeliverable'), 'error');
        } catch (VolumeLimitReachedException $exception) {
            return $this->back(trans('conversation.notifications.reply_limit_reached') . ' ' . $exception->getMessage(), 'error');
        } catch (StorageLimitReachedException $exception) {
            // The media library is full: a refusal the operator acts on, not a failure to report. Nothing was sent.
            return $this->back(StorageLimitMessage::for($exception), 'error');
        } catch (Throwable $exception) {
            report($exception);

            return $this->back(trans('conversation.notifications.reply_failed'), 'error');
        }

        return match ($outcome) {
            ReplyOutcome::Sent      => $this->back(trans('conversation.notifications.reply_sent')),
            ReplyOutcome::Duplicate => $this->back(trans('conversation.notifications.reply_duplicate')),
            // An error, so the screen keeps the draft and its id: a retry after checking the transcript sends nothing twice.
            ReplyOutcome::Unconfirmed => $this->back(trans('conversation.notifications.reply_unconfirmed'), 'error'),
            ReplyOutcome::Failed      => $this->back(trans('conversation.notifications.reply_failed'), 'error'),
        };
    }

    public function takeOver(Request $request, string $assistantKey, string $record): RedirectResponse
    {
        $conversation = $this->inbox->find($this->assistant->get(), $record);

        Gate::authorize('reply', $conversation);

        if (! $this->inbox->takeOver($conversation, $this->userId($request))) {
            return $this->back($this->alreadyHandled($conversation), 'error');
        }

        return $this->back(trans('conversation.notifications.taken_over'));
    }

    public function finish(Request $request, string $assistantKey, string $record): RedirectResponse
    {
        $conversation = $this->inbox->find($this->assistant->get(), $record);

        Gate::authorize('reply', $conversation);

        if (! $this->inbox->finish($conversation, $this->userId($request))) {
            return $this->back(trans('conversation.notifications.not_holder'), 'error');
        }

        return $this->back(trans('conversation.notifications.returned_to_bot'));
    }

    public function transfer(TransferConversationRequest $request, string $assistantKey, string $record): RedirectResponse
    {
        $assistant    = $this->assistant->get();
        $conversation = $this->inbox->find($assistant, $record);

        try {
            $moved = $this->inbox->transfer($assistant, $conversation, $this->userId($request), $request->targetId());
        } catch (ConversationTransferTargetIneligibleException) {
            return $this->back(trans('conversation.notifications.transfer_ineligible'), 'error');
        }

        if (! $moved) {
            return $this->back(trans('conversation.notifications.not_holder'), 'error');
        }

        $names = $this->inbox->staffNames([$request->targetId()]);

        return $this->back(trans('conversation.notifications.transferred', ['name' => $names[$request->targetId()] ?? '']));
    }

    public function seize(SeizeConversationRequest $request, string $assistantKey, string $record): RedirectResponse
    {
        $conversation = $this->inbox->find($this->assistant->get(), $record);
        $user         = $request->user();

        if (! $user instanceof User || ! $this->inbox->seize($conversation, $request->expectedOwner(), $user)) {
            return $this->back($this->alreadyHandled($conversation), 'error');
        }

        return $this->back(trans('conversation.notifications.seized'));
    }

    /**
     * The toast for a take over or seizure that lost: who holds the thread now, read fresh.
     */
    private function alreadyHandled(Conversation $conversation): string
    {
        $holder = $conversation->fresh();
        $id     = ConversationOwner::Staff === $holder?->owner_type && null !== $holder->owner_staff_user_id ? (string) $holder->owner_staff_user_id : null;
        $name   = null !== $id ? ($this->inbox->staffNames([$id])[$id] ?? null) : null;

        return null !== $name
            ? trans('conversation.notifications.already_handled_by', ['name' => $name])
            : trans('conversation.notifications.already_taken_over');
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * @param  array<string, string|null>  $names  Names resolved so far, by operator id.
     */
    private function ownerName(string $id, array &$names): ?string
    {
        if (! array_key_exists($id, $names)) {
            $names[$id] = $this->inbox->staffNames([$id])[$id] ?? null;
        }

        return $names[$id];
    }

    /**
     * The transcript as the page shows it. Media are named by signed links only, built for files of this tenant.
     *
     * @param  iterable<int, ConversationMessage>  $messages
     *
     * @return list<array<string, mixed>>
     */
    private function messages(Conversation $conversation, iterable $messages): array
    {
        $rows      = [];
        $staffIds  = [];
        $mediaUrls = [];

        foreach ($messages as $message) {
            if (null !== $message->sender_staff_user_id) {
                $staffIds[] = (string) $message->sender_staff_user_id;
            }
        }

        $staffNames = $this->inbox->staffNames($staffIds);
        $contact    = $this->inbox->contactLabel($conversation);

        foreach ($messages as $message) {
            $media = [];

            foreach (is_array($message->media) ? $message->media : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $status = is_string($item['status'] ?? null) ? $item['status'] : null;
                $fileId = is_string($item['media_file_id'] ?? null) ? $item['media_file_id'] : null;
                $url    = null;

                if ('ready' === $status && null !== $fileId) {
                    $url = $mediaUrls[$fileId] ??= $this->inbox->mediaUrl($fileId);
                }

                $media[] = [
                    'kind'     => is_string($item['kind'] ?? null) ? $item['kind'] : null,
                    'fileName' => is_string($item['file_name'] ?? null) ? $item['file_name'] : null,
                    'status'   => $status,
                    'url'      => $url,
                ];
            }

            $rows[] = [
                'id'         => (string) $message->getKey(),
                'direction'  => $message->direction->value,
                'senderType' => $message->sender_type->value,
                'author'     => match ($message->sender_type) {
                    MessageSenderType::Contact   => $contact,
                    MessageSenderType::Assistant => trans('conversation.authors.bot'),
                    MessageSenderType::System    => trans('conversation.authors.system'),
                    MessageSenderType::Staff     => $staffNames[(string) $message->sender_staff_user_id] ?? trans('conversation.authors.staff'),
                },
                'contentType' => $message->content_type->value,
                'text'        => $message->text,
                'media'       => $media,
                'buttons'     => $this->buttons($message),
                'delivery'    => MessageDirection::Outbound === $message->direction ? $message->status->value : null,
                'createdAt'   => $message->created_at->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * The labels of the buttons a bot message carried, for the transcript to show what the contact could press.
     * Reads the neutral `buttons` first, then the raw `keyboard` markup older messages were stored with.
     *
     * @return list<string>
     */
    private function buttons(ConversationMessage $message): array
    {
        $neutral = is_array($message->payload) ? ($message->payload['buttons'] ?? null) : null;

        if (is_array($neutral)) {
            $labels = [];

            foreach (Arr::flatten($neutral, 1) as $button) {
                $label = is_array($button) ? ($button['label'] ?? null) : null;

                if (is_string($label) && '' !== $label) {
                    $labels[] = $label;
                }
            }

            return $labels;
        }

        $keyboard = is_array($message->payload) ? ($message->payload['keyboard'] ?? null) : null;

        if (! is_array($keyboard)) {
            return [];
        }

        $labels = [];

        foreach (Arr::flatten($keyboard, 1) as $button) {
            $label = is_array($button) ? ($button['text'] ?? $button['label'] ?? null) : $button;

            if (is_string($label) && '' !== $label) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function owner(Conversation $conversation): ConversationOwner
    {
        return $conversation->owner_type ?? ConversationOwner::Bot;
    }

    /**
     * How many of the latest messages to show: `?messages=` rounded up to a page, between one page and the maximum.
     */
    private function messageLimit(Request $request): int
    {
        $asked = $request->query('messages');
        $asked = is_string($asked) && ctype_digit($asked) ? (int) $asked : self::MESSAGES_PAGE;
        $pages = (int) ceil(max(1, $asked) / self::MESSAGES_PAGE);

        return min(max(1, $pages) * self::MESSAGES_PAGE, self::MESSAGES_MAX);
    }

    /**
     * The channel the page listens to. Rendering the page counts as watching the assistant's conversations.
     *
     * @return array{channel: string, event: string}
     */
    private function live(Assistant $assistant): array
    {
        $this->watchers->watch((string) $assistant->tenant_id, (string) $assistant->getKey());

        return [
            'channel' => ConversationActivityChannel::name((string) $assistant->tenant_id, (string) $assistant->getKey()),
            'event'   => ConversationActivityChannel::EVENT,
        ];
    }

    /**
     * Back to the thread (or the list) with a toast. `back()` keeps the thread's `?messages=` paging.
     */
    private function back(string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->back(fallback: $this->url('index', $this->assistant->get()));
    }

    /**
     * A relative URL of one of this screen's routes, inside the given assistant's console.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, Assistant $assistant, array $parameters = []): string
    {
        return route('console.conversations.' . $action, ['assistant' => (string) $assistant->getKey(), ...$parameters], false);
    }
}
