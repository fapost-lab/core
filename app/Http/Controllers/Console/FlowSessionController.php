<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowActivityPeriod;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Enums\RerunRefusal;
use App\Domains\Flow\Live\FlowActivityChannel;
use App\Domains\Flow\Live\FlowActivityWatchers;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\AutomationRunRerunService;
use App\Domains\Flow\Services\FlowSessionInspector;
use App\Domains\Flow\Templates\TemplateNodeOrigins;
use App\Domains\Flow\Trace\RunGraphReader;
use App\Domains\Flow\Trace\RunPayloadReader;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\RerunFlowSessionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flow sessions on the Inertia console: the assistant's sessions, live ones by default, and one session's details, state
 * and audit history. Read-only, like the Filament resource it replaces: sessions are the engine's. The one write is a
 * re-run from the start of a failed automation run ({@see rerun()}), which starts a new run and leaves the session as
 * it is.
 *
 * Both pages stay current through the live channel of the assistant's flow activity ({@see FlowActivityChannel}), or by
 * polling when no broadcaster is configured.
 */
final class FlowSessionController extends Controller
{
    /** Stands for the visit's id in `urls.payload`; the page puts the id in its place. */
    private const string ENTRY_PLACEHOLDER = '__ENTRY__';

    public function __construct(
        private readonly FlowSessionInspector $sessions,
        private readonly CurrentAssistantInterface $assistant,
        private readonly FlowActivityWatchers $watchers,
        private readonly TemplateNodeOrigins $origins,
        private readonly RunGraphReader $graphs,
        private readonly RunPayloadReader $payloads,
        private readonly AutomationRunRerunService $reruns,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FlowSession::class);

        $assistant = $this->assistant->get();
        $now       = CarbonImmutable::now();
        $table     = new DataTable(
            sortable: ['updated_at', 'created_at'],
            searchable: ['contact_external_id'],
            defaultSort: '-updated_at',
            filters: [
                // Applied by the query itself, since its absence means "live"; here it is only accepted and echoed back.
                'status' => fn (Builder $query, string $value): bool => $this->sessions->acceptsStatus($value),
                'flow'   => fn (Builder $query, string $value): bool => $this->sessions->filterByFlow($query, $assistant, $value),
                'period' => fn (Builder $query, string $value): bool => $this->sessions->filterByPeriod($query, $value, $now),
            ],
            searchAlso: fn (Builder $query, string $search) => $this->sessions->searchContactId($query, $search),
        );

        return Inertia::render('Console/FlowSessions/Index', [
            'table' => $table->respond(
                $request,
                $this->sessions->query($assistant, $this->requestedFilter($request, 'status')),
                fn (FlowSession $session): array => [
                    'id'                => (string) $session->getKey(),
                    'shortId'           => mb_substr((string) $session->getKey(), 0, 8),
                    'status'            => $session->status->value,
                    'endStatus'         => $this->text($session->getAttribute('end_status')),
                    'contactExternalId' => $this->text($session->getAttribute('contact_external_id')),
                    'flowName'          => $this->text($session->flowDefinition?->name),
                    'currentNodeId'     => $this->text($session->current_node_id),
                    'currentNodeOrigin' => $this->origins->describe($this->text($session->flow_definition_id), $this->text($session->current_node_id)),
                    'updatedAt'         => $session->updated_at?->toIso8601String(),
                    'viewUrl'           => $this->url('flow-sessions.show', $assistant, ['record' => $session->getKey()]),
                ],
            ),
            'statuses' => array_map(static fn (FlowSessionStatus $status): string => $status->value, FlowSessionStatus::cases()),
            'flows'    => $this->sessions->flowOptions($assistant),
            'periods'  => array_map(static fn (FlowActivityPeriod $period): string => $period->value, FlowActivityPeriod::cases()),
            'live'     => $this->live($assistant),
            'urls'     => ['index' => $this->url('flow-sessions.index', $assistant)],
        ]);
    }

    /*
     * Route parameters reach an action by position, so `$assistantKey` (the assistant in the URL, already resolved by the
     * console stack) is declared ahead of `$record`.
     */
    public function show(string $assistantKey, string $record): Response
    {
        $assistant = $this->assistant->get();
        $session   = $this->sessions->findFor($assistant, $record);

        Gate::authorize('view', $session);

        $key      = (string) $session->getKey();
        $parentId = $this->text($session->getAttribute('parent_session_id'));

        return Inertia::render('Console/FlowSessions/Show', [
            'session' => [
                'id'                 => $key,
                'status'             => $session->status->value,
                'endStatus'          => $this->text($session->getAttribute('end_status')),
                'currentNodeId'      => $this->text($session->current_node_id),
                'currentNodeOrigin'  => $this->origins->describe($this->text($session->flow_definition_id), $this->text($session->current_node_id)),
                'version'            => (int) $session->version,
                'flowName'           => $this->text($session->flowDefinition?->name),
                'flowVersion'        => (int) $session->flow_version,
                'contactExternalId'  => $this->text($session->getAttribute('contact_external_id')),
                'createdAt'          => $session->created_at?->toIso8601String(),
                'updatedAt'          => $session->updated_at?->toIso8601String(),
                'parentId'           => $parentId,
                'parentResumeNodeId' => $this->text($session->getAttribute('parent_resume_node_id')),
                'expiresAt'          => $session->expires_at?->toIso8601String(),
                'isLive'             => in_array($session->status, FlowSessionStatus::live(), true),
            ],
            'rerun'  => $this->rerunOffer($assistant, $session),
            'reruns' => array_map(fn (FlowSession $rerun): array => [
                'id'        => (string) $rerun->getKey(),
                'status'    => $rerun->status->value,
                'isRunning' => $this->isRunning($rerun),
                'createdAt' => $rerun->created_at?->toIso8601String(),
                'url'       => $this->url('flow-sessions.show', $assistant, ['record' => $rerun->getKey()]),
            ], $this->sessions->reruns($assistant, $session)),
            'rerunOf' => $this->rerunOf($assistant, $session),
            'state'   => $this->sessions->flattenState(is_array($session->state) ? $session->state : []),
            'history' => $this->sessions->hasHistory($session) ? $this->sessions->history($session) : null,
            'live'    => $this->live($assistant),
            'urls'    => [
                'index' => $this->url('flow-sessions.index', $assistant),
                'graph' => $this->url('flow-sessions.graph', $assistant, ['record' => $key]),
                ...(null !== $parentId ? ['parent' => $this->url('flow-sessions.show', $assistant, ['record' => $parentId])] : []),
                // The session's own log, over the longest period a log is kept.
                ...(Gate::allows('viewAny', FlowLog::class) ? ['logs' => $this->url('flow-logs.index', $assistant, [
                    'filter' => ['session' => $key, 'period' => FlowActivityPeriod::LastMonth->value],
                ])] : []),
            ],
        ]);
    }

    /**
     * Re-runs a failed automation run from the start, with the trigger payload it started with: queues the attempt the
     * page offered and goes back to the session's page, which lists the new run once it has started. The page's URL is fixed before the service
     * runs: a synchronous queue runs the job inside the request, and the tenant switch it makes resets the current
     * assistant.
     */
    public function rerun(RerunFlowSessionRequest $request, string $assistantKey, string $record): RedirectResponse
    {
        $assistant = $this->assistant->get();
        $session   = $this->sessions->findFor($assistant, $record);
        $page      = $this->url('flow-sessions.show', $assistant, ['record' => $session->getKey()]);
        $outcome   = $this->reruns->rerun($session, $request->attempt());

        if (null !== $outcome->refusal) {
            Inertia::flash('error', trans('console.flow_sessions.rerun.refusals.' . $outcome->refusal->value));
        } else {
            Inertia::flash('success', trans('console.flow_sessions.rerun.queued'));
        }

        return redirect()->to($page);
    }

    /**
     * The run graph of a session: the definition it executed with what each node did, its loops and its subflow
     * children. Statuses only ({@see RunGraphReader}); a node's visits load as JSON from {@see visits()}, and for a holder
     * of `ViewRunPayloads` one visit's data from {@see payload()} (`urls.payload`, with `__ENTRY__` for the visit's id).
     */
    public function graph(string $assistantKey, string $record): Response
    {
        $assistant = $this->assistant->get();
        $session   = $this->sessions->findFor($assistant, $record);

        Gate::authorize('view', $session);

        $key             = (string) $session->getKey();
        $canViewPayloads = Gate::allows('viewPayloads', $session);

        return Inertia::render('Console/FlowSessions/Graph', [
            'session' => [
                'id'       => $key,
                'status'   => $session->status->value,
                'flowName' => $this->text($session->flowDefinition?->name),
                'isLive'   => in_array($session->status, FlowSessionStatus::live(), true),
            ],
            'graph'           => $this->graphs->graph($session),
            'canViewPayloads' => $canViewPayloads,
            'live'            => $this->live($assistant),
            'urls'            => [
                'session' => $this->url('flow-sessions.show', $assistant, ['record' => $key]),
                'visits'  => $this->url('flow-sessions.visits', $assistant, ['record' => $key]),
                'payload' => $canViewPayloads ? $this->url('flow-sessions.payload', $assistant, ['record' => $key, 'entry' => self::ENTRY_PLACEHOLDER]) : null,
            ],
        ]);
    }

    /**
     * The newest visits of one node of the run graph, statuses only. `session` is the run's own session or one of its
     * subflow children (a frame of the graph); any other session is not found. For a holder of `ViewRunPayloads` each
     * visit says whether it carries data (`hasData`), never the data itself.
     */
    public function visits(string $assistantKey, string $record, Request $request): JsonResponse
    {
        $assistant = $this->assistant->get();
        $root      = $this->sessions->findFor($assistant, $record);

        Gate::authorize('view', $root);

        $nodeId = $request->query('node');

        if (! is_string($nodeId) || '' === $nodeId) {
            throw (new ModelNotFoundException())->setModel(FlowSession::class);
        }

        $session = $this->frameSession($assistant, $root, $request);
        $visits  = $this->graphs->visits($session, $nodeId);

        if (Gate::allows('viewPayloads', $root)) {
            $present = $this->payloads->presence($session, array_column($visits, 'id'));
            $visits  = array_map(static fn (array $visit): array => [...$visit, 'hasData' => isset($present[$visit['id']])], $visits);
        }

        return response()->json(['visits' => $visits]);
    }

    /**
     * The data of one node visit of the run graph — its input, output and error message, masked and limited as the trace
     * wrote them ({@see RunPayloadReader}) — for a holder of `ViewRunPayloads`. `session` is a frame of the run, as for
     * {@see visits()}; a visit of any other session, a history row that is not the trace and an unknown id are all
     * not found. Not cached: it can hold contact data.
     */
    public function payload(string $assistantKey, string $record, string $entry, Request $request): JsonResponse
    {
        $assistant = $this->assistant->get();
        $root      = $this->sessions->findFor($assistant, $record);

        Gate::authorize('viewPayloads', $root);

        $payload = $this->payloads->payload($this->frameSession($assistant, $root, $request), $entry);

        if (null === $payload) {
            throw (new ModelNotFoundException())->setModel(FlowSession::class, [$entry]);
        }

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    /**
     * What the page offers to re-run the session from the start, for a user who may: whether it can be re-run now, why
     * not, and the flow version the re-run would run. Null for a user who may not and for a session that is never
     * re-run (a conversation, a subflow child), so the page shows no button there.
     *
     * @return array{allowed: bool, refusal: string|null, targetVersion: int|null, attempt: int|null, url: string}|null
     */
    private function rerunOffer(Assistant $assistant, FlowSession $session): ?array
    {
        if (! Gate::allows('rerun', $session)) {
            return null;
        }

        $check = $this->reruns->check($session);

        if (in_array($check->refusal, [RerunRefusal::NotAnAutomation, RerunRefusal::NotARoot], true)) {
            return null;
        }

        return [
            'allowed'       => ! $check->isRefused(),
            'refusal'       => $check->refusal?->value,
            'targetVersion' => $check->targetVersion,
            'attempt'       => $check->attempt,
            'url'           => $this->url('flow-sessions.rerun', $assistant, ['record' => $session->getKey()]),
        ];
    }

    /**
     * The session this one re-runs, when it is a re-run whose source the assistant still has.
     *
     * @return array{id: string, url: string}|null
     */
    private function rerunOf(Assistant $assistant, FlowSession $session): ?array
    {
        $source = $this->sessions->rerunSource($assistant, $session);

        return null === $source ? null : [
            'id'  => (string) $source->getKey(),
            'url' => $this->url('flow-sessions.show', $assistant, ['record' => $source->getKey()]),
        ];
    }

    /**
     * The session of a graph frame a node request names in `session`: the run's own session or one of its subflow
     * children. Any other session is not found.
     */
    private function frameSession(Assistant $assistant, FlowSession $root, Request $request): FlowSession
    {
        $sessionId = $request->query('session');

        if (! is_string($sessionId)) {
            throw (new ModelNotFoundException())->setModel(FlowSession::class);
        }

        $session = $sessionId === (string) $root->getKey() ? $root : $this->sessions->findFor($assistant, $sessionId);

        if (! $this->graphs->belongsTo($root, $session)) {
            throw (new ModelNotFoundException())->setModel(FlowSession::class, [$sessionId]);
        }

        return $session;
    }

    /**
     * The channel the page listens to. Rendering the page counts as watching the assistant, so its activity is announced
     * while the page is open (the page reloads on a heartbeat to keep it so).
     *
     * @return array{channel: string, event: string}
     */
    private function live(Assistant $assistant): array
    {
        $this->watchers->watch((string) $assistant->tenant_id, (string) $assistant->getKey());

        return [
            'channel' => FlowActivityChannel::name((string) $assistant->tenant_id, (string) $assistant->getKey()),
            'event'   => FlowActivityChannel::EVENT,
        ];
    }

    /**
     * Whether a run may still move soon, so a page listing it keeps itself current: a live one, or one still queued to
     * start (`paused` sleeps in a delay and is not).
     */
    private function isRunning(FlowSession $session): bool
    {
        return FlowSessionStatus::Pending === $session->status || in_array($session->status, FlowSessionStatus::live(), true);
    }

    private function requestedFilter(Request $request, string $key): ?string
    {
        $filters = $request->query('filter');
        $value   = is_array($filters) ? ($filters[$key] ?? null) : null;

        return is_string($value) ? mb_trim($value) : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = (string) $value;

        return '' === $text ? null : $text;
    }

    /**
     * A relative URL of one of the assistant's flow screens (`<resource>.<action>` under `console.`).
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $screen, Assistant $assistant, array $parameters = []): string
    {
        return route('console.' . $screen, ['assistant' => (string) $assistant->getKey(), ...$parameters], false);
    }
}
