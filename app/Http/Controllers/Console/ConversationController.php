<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\ConversationStatus;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Enums\ReplyOutcome;
use App\Domains\Conversation\Exceptions\ConversationNotHeldByStaffException;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Live\ConversationActivityChannel;
use App\Domains\Conversation\Live\ConversationActivityWatchers;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\ConversationMessage;
use App\Domains\Conversation\Services\ConversationInbox;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Services\StorageLimitMessage;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\ReplyConversationRequest;
use App\Http\Requests\Console\UpdateConversationStatusRequest;
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
 * operator's controls (take over, hand back, close or reopen, reply), replacing the Filament conversation resource.
 *
 * Reading needs `view` on conversations; every write needs `reply`. Opening a thread clears its unread counter only
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
        $table     = new DataTable(
            sortable: ['last_message_at'],
            searchable: [],
            defaultSort: '-last_message_at',
            filters: [
                'status' => static function (Builder $query, string $value): bool {
                    $status = ConversationStatus::tryFrom($value);

                    if (null === $status) {
                        return false;
                    }

                    $query->where('conversations.status', $status->value);

                    return true;
                },
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
            'table' => $table->respond(
                $request,
                $this->inbox->query($assistant)->with('contact'),
                fn (Conversation $conversation): array => [
                    'id'            => (string) $conversation->getKey(),
                    'contact'       => $this->inbox->contactLabel($conversation),
                    'platform'      => (string) $conversation->platform,
                    'preview'       => $conversation->last_message_preview,
                    'unread'        => (int) $conversation->unread_count,
                    'messages'      => (int) $conversation->message_count,
                    'status'        => $conversation->status->value,
                    'owner'         => $this->owner($conversation)->value,
                    'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
                    'viewUrl'       => $this->url('view', $assistant, ['record' => $conversation->getKey()]),
                ],
            ),
            'statuses' => array_map(static fn (ConversationStatus $status): string => $status->value, ConversationStatus::cases()),
            'live'     => $this->live($assistant),
            'urls'     => ['index' => $this->url('index', $assistant)],
        ]);
    }

    /*
     * Route parameters reach an action by position, so `$tenant` (the assistant in the URL, already resolved by the
     * console stack) is declared ahead of `$record`.
     */
    public function show(Request $request, string $tenant, string $record): Response
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
        $canReply = Gate::allows('reply', $conversation);
        $key      = (string) $conversation->getKey();

        return Inertia::render('Console/Conversations/Show', [
            'conversation' => [
                'id'        => $key,
                'contact'   => $this->inbox->contactLabel($conversation),
                'platform'  => (string) $conversation->platform,
                'status'    => $conversation->status->value,
                'owner'     => $owner->value,
                'ownerName' => ConversationOwner::Staff === $owner && null !== $conversation->owner_staff_user_id
                    ? ($this->inbox->staffNames([(string) $conversation->owner_staff_user_id])[(string) $conversation->owner_staff_user_id] ?? null)
                    : null,
                'messageCount' => (int) $conversation->message_count,
            ],
            'messages' => $this->messages($conversation, $messages),
            'paging'   => [
                'limit'   => $limit,
                'hasMore' => $conversation->message_count > $messages->count() && $limit < self::MESSAGES_MAX,
                'older'   => min($limit + self::MESSAGES_PAGE, self::MESSAGES_MAX),
            ],
            'can' => [
                'reply'       => $canReply,
                'compose'     => $canReply && ConversationOwner::Staff === $owner,
                'takeOver'    => $canReply && ConversationOwner::Staff !== $owner,
                'returnToBot' => $canReply && ConversationOwner::Staff === $owner,
                'setStatus'   => $canReply,
            ],
            'attachment' => [
                'maxKb'  => ReplyConversationRequest::attachmentMaxKb(),
                'accept' => implode(',', ReplyConversationRequest::allowedMimeTypes()),
            ],
            'textMax' => ReplyConversationRequest::TEXT_MAX,
            'live'    => $this->live($assistant),
            'urls'    => [
                'index'       => $this->url('index', $assistant),
                'self'        => $this->url('view', $assistant, ['record' => $key]),
                'reply'       => $this->url('reply', $assistant, ['record' => $key]),
                'takeOver'    => $this->url('takeover', $assistant, ['record' => $key]),
                'returnToBot' => $this->url('return-to-bot', $assistant, ['record' => $key]),
                'status'      => $this->url('status', $assistant, ['record' => $key]),
            ],
        ]);
    }

    public function reply(ReplyConversationRequest $request, string $tenant, string $record): RedirectResponse
    {
        $assistant    = $this->assistant->get();
        $conversation = $this->inbox->find($assistant, $record);

        try {
            $outcome = $this->inbox->reply(
                $conversation,
                (string) $request->user()?->getAuthIdentifier(),
                $request->requestId(),
                $request->text(),
                $request->attachment(),
            );
        } catch (ConversationNotHeldByStaffException) {
            return $this->back(trans('conversation.notifications.reply_requires_takeover'), 'error');
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

    public function takeOver(Request $request, string $tenant, string $record): RedirectResponse
    {
        $conversation = $this->inbox->find($this->assistant->get(), $record);

        Gate::authorize('reply', $conversation);

        if (! $this->inbox->takeOver($conversation, (string) $request->user()?->getAuthIdentifier())) {
            return $this->back(trans('conversation.notifications.already_taken_over'), 'error');
        }

        return $this->back(trans('conversation.notifications.taken_over'));
    }

    public function returnToBot(string $tenant, string $record): RedirectResponse
    {
        $conversation = $this->inbox->find($this->assistant->get(), $record);

        Gate::authorize('reply', $conversation);

        $this->inbox->returnToBot($conversation);

        return $this->back(trans('conversation.notifications.returned_to_bot'));
    }

    public function updateStatus(UpdateConversationStatusRequest $request, string $tenant, string $record): RedirectResponse
    {
        $conversation = $this->inbox->find($this->assistant->get(), $record);
        $status       = $request->status();

        $this->inbox->setStatus($conversation, $status);

        return $this->back(trans('conversation.notifications.status_changed', [
            'status' => trans('conversation.statuses.' . $status->value),
        ]));
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
     * The labels of the inline keyboard a bot message carried, for the transcript to show what the contact could press.
     *
     * @return list<string>
     */
    private function buttons(ConversationMessage $message): array
    {
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
        $name = match ($action) {
            'index', 'view' => 'filament.assistant.resources.conversations.' . $action,
            default         => 'console.conversations.' . $action,
        };

        return route($name, ['tenant' => (string) $assistant->getKey(), ...$parameters], false);
    }
}
