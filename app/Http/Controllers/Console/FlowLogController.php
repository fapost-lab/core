<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowActivityPeriod;
use App\Domains\Flow\Live\FlowActivityChannel;
use App\Domains\Flow\Live\FlowActivityWatchers;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowLogInspector;
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
 * The flow log on the Inertia console: one row per executed node, over a period that is never unbounded (the last
 * 24 hours unless another is chosen), and one entry's details. Read-only, like the Filament resource it replaces.
 *
 * {@see FlowLogInspector} bounds every query by time and by the assistant's sessions. The list stays current through the
 * live channel of the assistant's flow activity, or by polling when no broadcaster is configured.
 */
final class FlowLogController extends Controller
{
    public function __construct(
        private readonly FlowLogInspector $logs,
        private readonly CurrentAssistantInterface $assistant,
        private readonly FlowActivityWatchers $watchers,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FlowLog::class);

        $assistant   = $this->assistant->get();
        $period      = FlowActivityPeriod::tryFrom($this->requestedFilter($request, 'period') ?? '') ?? FlowActivityPeriod::default();
        $canSessions = Gate::allows('viewAny', FlowSession::class);
        $table       = new DataTable(
            sortable: ['created_at'],
            searchable: ['node_id'],
            defaultSort: '-created_at',
            perPageOptions: [25, 50, 100, 250],
            filters: [
                // Applied by the query itself, since its absence means the default period; here it is only echoed back.
                'period'  => static fn (Builder $query, string $value): bool => null !== FlowActivityPeriod::tryFrom($value),
                'session' => fn (Builder $query, string $value): bool => $this->logs->filterBySession($query, $value),
                'type'    => fn (Builder $query, string $value): bool => $this->logs->filterByNodeType($query, $value),
                'status'  => fn (Builder $query, string $value): bool => $this->logs->filterByStatus($query, $value),
                'errors'  => fn (Builder $query, string $value): bool => $this->logs->filterByErrors($query, $value),
            ],
        );

        return Inertia::render('Console/FlowLogs/Index', [
            'table' => $table->respond(
                $request,
                $this->logs->query($assistant, $period, CarbonImmutable::now()),
                fn (FlowLog $log): array => [
                    'id'             => (string) $log->getKey(),
                    'createdAt'      => $log->created_at->toIso8601String(),
                    'status'         => $log->status,
                    'nodeType'       => $log->node_type,
                    'nodeId'         => $log->node_id,
                    'sourceHandle'   => '' === (string) $log->source_handle ? null : (string) $log->source_handle,
                    'hasError'       => is_array($log->error) && [] !== $log->error,
                    'sessionId'      => (string) $log->session_id,
                    'sessionShortId' => mb_substr((string) $log->session_id, 0, 8),
                    'viewUrl'        => $this->url('flow-logs.view', $assistant, ['record' => $log->getKey()]),
                    'sessionUrl'     => $canSessions ? $this->url('flow-sessions.view', $assistant, ['record' => $log->session_id]) : null,
                ],
            ),
            'period'    => $period->value,
            'periods'   => array_map(static fn (FlowActivityPeriod $case): string => $case->value, FlowActivityPeriod::cases()),
            'statuses'  => array_map(static fn (FlowLogStatus $case): string => $case->value, FlowLogStatus::cases()),
            'nodeTypes' => $this->logs->nodeTypes(),
            'live'      => $this->live($assistant),
            'urls'      => ['index' => $this->url('flow-logs.index', $assistant)],
        ]);
    }

    /*
     * Route parameters reach an action by position, so `$tenant` (the assistant in the URL, already resolved by the
     * console stack) is declared ahead of `$record`.
     */
    public function show(string $tenant, string $record): Response
    {
        $assistant = $this->assistant->get();
        $log       = $this->logs->findFor($assistant, $record);

        Gate::authorize('view', $log);

        return Inertia::render('Console/FlowLogs/Show', [
            'log' => [
                'id'           => (string) $log->getKey(),
                'createdAt'    => $log->created_at->toIso8601String(),
                'status'       => $log->status,
                'sessionId'    => (string) $log->session_id,
                'nodeId'       => $log->node_id,
                'nodeType'     => $log->node_type,
                'nodeVersion'  => $log->node_version,
                'sourceHandle' => '' === (string) $log->source_handle ? null : (string) $log->source_handle,
            ],
            'stateChanges' => $this->pairs($log->state_changes),
            'resolved'     => $this->pairs($log->resolved),
            'error'        => $this->pairs($log->error),
            'urls'         => [
                'index' => $this->url('flow-logs.index', $assistant),
                ...(Gate::allows('viewAny', FlowSession::class)
                    ? ['session' => $this->url('flow-sessions.view', $assistant, ['record' => $log->session_id])]
                    : []),
            ],
        ]);
    }

    /**
     * A JSON object as a list of `{key, value}` sorted by key (`jsonb` keeps no key order), values as one line of text;
     * as a list, the order survives JSON and the browser.
     *
     * @param  array<array-key, mixed>|null  $map
     *
     * @return list<array{key: string, value: string}>
     */
    private function pairs(?array $map): array
    {
        $pairs = [];

        foreach ($map ?? [] as $key => $value) {
            $pairs[] = ['key' => (string) $key, 'value' => FlowSessionInspector::stringify($value)];
        }

        return FlowSessionInspector::sortByKey($pairs);
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
