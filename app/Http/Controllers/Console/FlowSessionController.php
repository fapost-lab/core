<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowActivityPeriod;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Live\FlowActivityChannel;
use App\Domains\Flow\Live\FlowActivityWatchers;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowSessionInspector;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flow sessions on the Inertia console: the assistant's sessions, live ones by default, and one session's details, state
 * and audit history. Read-only, like the Filament resource it replaces: sessions are the engine's.
 *
 * Both pages stay current through the live channel of the assistant's flow activity ({@see FlowActivityChannel}), or by
 * polling when no broadcaster is configured.
 */
final class FlowSessionController extends Controller
{
    public function __construct(
        private readonly FlowSessionInspector $sessions,
        private readonly CurrentAssistantInterface $assistant,
        private readonly FlowActivityWatchers $watchers,
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
                    'updatedAt'         => $session->updated_at?->toIso8601String(),
                    'viewUrl'           => $this->url('flow-sessions.view', $assistant, ['record' => $session->getKey()]),
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
     * Route parameters reach an action by position, so `$tenant` (the assistant in the URL, already resolved by the
     * console stack) is declared ahead of `$record`.
     */
    public function show(string $tenant, string $record): Response
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
            'state'   => $this->sessions->flattenState(is_array($session->state) ? $session->state : []),
            'history' => $this->sessions->hasHistory($session) ? $this->sessions->history($session) : null,
            'live'    => $this->live($assistant),
            'urls'    => [
                'index' => $this->url('flow-sessions.index', $assistant),
                ...(null !== $parentId ? ['parent' => $this->url('flow-sessions.view', $assistant, ['record' => $parentId])] : []),
                // The session's own log, over the longest period a log is kept.
                ...(Gate::allows('viewAny', FlowLog::class) ? ['logs' => $this->url('flow-logs.index', $assistant, [
                    'filter' => ['session' => $key, 'period' => FlowActivityPeriod::LastMonth->value],
                ])] : []),
            ],
        ]);
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
     * A relative URL of one of the assistant's flow screens, which keep the names Filament gave them.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $screen, Assistant $assistant, array $parameters = []): string
    {
        return route('filament.assistant.resources.' . $screen, ['tenant' => (string) $assistant->getKey(), ...$parameters], false);
    }
}
