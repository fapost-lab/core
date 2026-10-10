<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Exceptions\BroadcastAlreadyStartedException;
use App\Domains\Broadcasting\Exceptions\BroadcastChangedException;
use App\Domains\Broadcasting\Exceptions\BroadcastNotEditableException;
use App\Domains\Broadcasting\Exceptions\BroadcastNotSendableException;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastRecipientResolver;
use App\Domains\Broadcasting\Services\BroadcastService;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\BroadcastReachRequest;
use App\Http\Requests\Console\BroadcastRequest;
use App\Http\Requests\Console\SendBroadcastRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Broadcasts on the Inertia console: the list with its progress, the form for a draft, and the transitions: send,
 * cancel, delete.
 *
 * A broadcast reaches real people, so the one irreversible step is guarded here and in {@see BroadcastService}: a draft
 * is sent only from the list's confirmation, with the revision that confirmation showed, and the service refuses a
 * revision that is stale, a broadcast that is not a draft any more, a base language without text and an audience that
 * is gone. This controller words those refusals as toasts. A repeated send starts nothing: only the request that wins
 * the draft-to-running UPDATE queues the run.
 */
final class BroadcastController extends Controller
{
    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly BroadcastRecipientResolver $resolver,
        private readonly CurrentAssistantInterface $assistant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Broadcast::class);

        $probe = $this->broadcasts->abilityProbe();
        $can   = [
            'create' => Gate::allows('create', Broadcast::class),
            'update' => Gate::allows('update', $probe),
            'delete' => Gate::allows('delete', $probe),
            'send'   => Gate::allows('send', $probe),
            'cancel' => Gate::allows('cancel', $probe),
        ];

        $table = new DataTable(
            sortable: ['created_at'],
            searchable: ['name'],
            defaultSort: '-created_at',
            filters: [
                'status' => static function (Builder $query, string $value): bool {
                    $status = BroadcastStatus::tryFrom($value);

                    if (null === $status) {
                        return false;
                    }

                    $query->where('broadcasts.status', $status->value);

                    return true;
                },
            ],
        );

        return Inertia::render('Console/Broadcasts/Index', [
            'table' => $table->respond(
                $request,
                $this->broadcasts->query(),
                fn (Broadcast $broadcast): array => $this->row($broadcast, $can),
            ),
            'statuses' => array_map(static fn (BroadcastStatus $status): string => $status->value, BroadcastStatus::cases()),
            // Names for the confirmation dialog, which shows the audience of the draft about to be sent.
            'segments' => $this->broadcasts->segmentOptions(),
            'can'      => $can,
            'urls'     => [
                'index'  => $this->url('index'),
                'create' => $this->url('create'),
                'reach'  => $this->url('reach'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Broadcast::class);

        return Inertia::render('Console/Broadcasts/Create', $this->formProps(null));
    }

    public function store(BroadcastRequest $request): RedirectResponse
    {
        $user = $request->user();

        $this->broadcasts->create($request->fields(), null === $user ? null : (string) $user->getAuthIdentifier());

        return $this->backToIndex(trans('console.broadcasts.created'));
    }

    /*
     * Route parameters reach an action by position, not by name, so `$tenant` (the assistant in the URL, already
     * resolved by the console stack) has to be declared ahead of `$record`.
     */
    public function edit(string $tenant, string $record): Response|RedirectResponse
    {
        $broadcast = $this->broadcasts->findForAssistant($record);

        Gate::authorize('update', $broadcast);

        // Someone arriving by an old link needs an explanation, not a 403.
        if (! $broadcast->status->isEditable()) {
            return $this->backToIndex(trans('console.broadcasts.errors.not_editable'), 'error');
        }

        return Inertia::render('Console/Broadcasts/Edit', $this->formProps($broadcast));
    }

    public function update(BroadcastRequest $request, string $tenant, string $record): RedirectResponse
    {
        try {
            $this->broadcasts->update($this->broadcasts->findForAssistant($record), $request->fields());
        } catch (BroadcastNotEditableException) {
            return $this->backToIndex(trans('console.broadcasts.errors.not_editable'), 'error');
        }

        return $this->backToIndex(trans('console.broadcasts.updated'));
    }

    /**
     * How many contacts the audience the form holds would reach, as a number: counted in the database, no models loaded.
     * It is an estimate of now; the audience is fixed when the run starts.
     */
    public function reach(BroadcastReachRequest $request): JsonResponse
    {
        /** @var array{target_type: string, target_tags?: list<string>, target_segment_id?: string} $data */
        $data = $request->validated();

        $audience = (new Broadcast())->forceFill([
            'assistant_id'      => (string) $this->assistant->get()->getKey(),
            'target_type'       => $data['target_type'],
            'target_tags'       => $data['target_tags'] ?? null,
            'target_segment_id' => $data['target_segment_id'] ?? null,
        ]);

        return response()->json(['count' => $this->resolver->count($audience)]);
    }

    /**
     * Starts a draft. The destination is fixed before the service runs: a synchronous queue runs the job inside the
     * request, and the tenant switch it makes resets the current assistant.
     */
    public function send(SendBroadcastRequest $request, string $tenant, string $record): RedirectResponse
    {
        $broadcast = $this->broadcasts->findForAssistant($record);
        $fallback  = $this->url('index');

        try {
            $this->broadcasts->send($broadcast, $request->revision());
        } catch (BroadcastAlreadyStartedException) {
            return $this->backToList(trans('console.broadcasts.errors.already_started'), $fallback, 'error');
        } catch (BroadcastChangedException) {
            return $this->backToList(trans('console.broadcasts.errors.changed'), $fallback, 'error');
        } catch (BroadcastNotSendableException $exception) {
            return $this->backToList(
                BroadcastNotSendableException::BASE_LANGUAGE === $exception->reason
                    ? trans('console.broadcasts.errors.base_language', ['language' => mb_strtoupper($this->broadcasts->baseLanguage())])
                    : trans('console.broadcasts.errors.audience_invalid'),
                $fallback,
                'error',
            );
        }

        return $this->backToList(trans('console.broadcasts.started'), $fallback);
    }

    public function cancel(string $tenant, string $record): RedirectResponse
    {
        $broadcast = $this->broadcasts->findForAssistant($record);

        Gate::authorize('cancel', $broadcast);

        if (! $this->broadcasts->cancel($broadcast)) {
            return $this->backToList(trans('console.broadcasts.errors.not_running'), $this->url('index'), 'error');
        }

        return $this->backToList(trans('console.broadcasts.cancelled'), $this->url('index'));
    }

    public function destroy(string $tenant, string $record): RedirectResponse
    {
        $broadcast = $this->broadcasts->findForAssistant($record);

        Gate::authorize('delete', $broadcast);

        if (! $this->broadcasts->delete($broadcast)) {
            return $this->backToList(trans('console.broadcasts.errors.running_delete'), $this->url('index'), 'error');
        }

        return $this->backToList(trans('console.broadcasts.deleted'), $this->url('index'));
    }

    /**
     * One row of the list. A link is there only when the broadcast's status allows the step, so the page never offers
     * one the server would refuse; the server checks again.
     *
     * @param  array{create: bool, update: bool, delete: bool, send: bool, cancel: bool}  $can
     *
     * @return array<string, mixed>
     */
    private function row(Broadcast $broadcast, array $can): array
    {
        $id      = $broadcast->getKey();
        $isDraft = BroadcastStatus::Draft === $broadcast->status;

        return [
            'id'      => (string) $id,
            'name'    => $broadcast->name,
            'target'  => $broadcast->target_type->value,
            'status'  => $broadcast->status->value,
            'sent'    => $broadcast->sent_count,
            'total'   => $broadcast->total_recipients,
            'failed'  => $broadcast->failed_count,
            'skipped' => $broadcast->skipped_count,
            // Only a draft carries the revision to confirm; nothing else can be sent.
            'revision' => $isDraft ? $this->broadcasts->revision($broadcast) : null,
            // What the confirmation dialog counts the reach of: the audience of the revision it shows.
            'targetTags'      => $isDraft ? array_values($broadcast->target_tags ?? []) : [],
            'targetSegmentId' => $isDraft ? $broadcast->target_segment_id : null,
            'createdAt'       => $broadcast->created_at?->toIso8601String(),
            'editUrl'         => $isDraft && $can['update'] ? $this->url('edit', ['record' => $id]) : null,
            'sendUrl'         => $isDraft && $can['send'] ? $this->url('send', ['record' => $id]) : null,
            'cancelUrl'       => BroadcastStatus::Running === $broadcast->status && $can['cancel'] ? $this->url('cancel', ['record' => $id]) : null,
            'deleteUrl'       => BroadcastStatus::Running !== $broadcast->status && $can['delete'] ? $this->url('destroy', ['record' => $id]) : null,
        ];
    }

    /**
     * The props the create and the edit page share. The message is a list in the order of the tabs: a JSON object
     * would reorder them, and `jsonb` does too.
     *
     * @return array<string, mixed>
     */
    private function formProps(?Broadcast $broadcast): array
    {
        $saved     = is_array($broadcast?->message) ? $broadcast->message : [];
        $languages = $this->broadcasts->languages($saved);
        $base      = $this->broadcasts->baseLanguage();
        $segments  = $this->broadcasts->segmentOptions();
        $tags      = $this->broadcasts->tagOptions();

        $segmentId      = $broadcast?->target_segment_id;
        $segmentMissing = null !== $broadcast
            && BroadcastTarget::Segment === $broadcast->target_type
            && (null === $segmentId || [] === array_filter($segments, static fn (array $option): bool => $option['value'] === $segmentId));

        return [
            'form' => [
                'name'    => $broadcast?->name ?? '',
                'message' => array_map(
                    static fn (string $language): array => ['locale' => $language, 'text' => is_string($saved[$language] ?? null) ? $saved[$language] : ''],
                    $languages,
                ),
                'targetType' => ($broadcast?->target_type ?? BroadcastTarget::All)->value,
                // A tag no contact carries any more is not offered, so the form can be saved as it is shown.
                'targetTags'      => array_values(array_intersect($broadcast?->target_tags ?? [], $tags)),
                'targetSegmentId' => $segmentMissing ? null : $segmentId,
                'segmentMissing'  => $segmentMissing,
            ],
            'languages' => array_map(static fn (string $language): array => ['code' => $language, 'isBase' => $language === $base], $languages),
            'options'   => ['tags' => $tags, 'segments' => $segments],
            'urls'      => [
                'index'  => $this->url('index'),
                'submit' => null === $broadcast ? $this->url('store') : $this->url('update', ['record' => $broadcast->getKey()]),
                'reach'  => $this->url('reach'),
            ],
        ];
    }

    /**
     * After a form: the list, as it opens by default. The message is Inertia flash data, so it reaches the toast once
     * and is not kept in the browser's history.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToIndex(string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->to($this->url('index'));
    }

    /**
     * After a step taken from the list: the list the user was on, with its search, sort, filter and page (the referer),
     * or the default list when there is none.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToList(string $message, string $fallback, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->back(fallback: $fallback);
    }

    /**
     * A relative URL of one of this screen's routes, inside the current assistant's console.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = match ($action) {
            'index', 'create', 'edit' => 'filament.assistant.resources.broadcasts.' . $action,
            default                   => 'console.broadcasts.' . $action,
        };

        return route($name, ['tenant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
