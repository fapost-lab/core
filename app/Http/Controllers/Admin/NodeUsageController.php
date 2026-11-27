<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Enums\FlowActivityPeriod;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Statistics\ActionUsage;
use App\Domains\Flow\Statistics\NodeTypeUsage;
use App\Domains\Flow\Statistics\NodeUsageReport;
use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Shell\AdminOverview;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The node usage screen of the admin panel: which node types (`type@version`) and which actions the tenant's flows use,
 * in how many active flows and published versions, and how often they ran over a period — what `flow:node-usage`
 * prints for one tenant, read through the same {@see NodeUsageStatisticsInterface}.
 *
 * It opens for whoever may list flows (`viewAny` of {@see FlowDraft}); the run figures are given only to whoever may
 * also list the flow log (`viewAny` of {@see FlowLog}), and are `null` otherwise. For anyone but an administrator,
 * flows and runs narrow to the assistants the user may list, as on the dashboard, and the registered types nothing
 * uses are not given: "nothing uses it" holds only over the whole tenant.
 *
 * The period is `?period` (one of {@see FlowActivityPeriod}, 30 days by default — as long as `flow_logs` keeps); it is
 * ignored without the flow log, and rows only the flow log knows of are left out then.
 */
final class NodeUsageController extends Controller
{
    /**
     * The query parameter that chooses the period of the run figures.
     */
    public const string PERIOD = 'period';

    /**
     * The period shown when none is chosen: the whole of what `flow_logs` keeps, as `flow:node-usage` reads by default.
     */
    public const FlowActivityPeriod DEFAULT_PERIOD = FlowActivityPeriod::LastMonth;

    public function index(Request $request, AdminOverview $overview, NodeUsageStatisticsInterface $statistics, NodeHandlerRegistryInterface $handlers): Response
    {
        Gate::authorize('viewAny', FlowDraft::class);

        $user = $request->user();

        // The `admin` stack signs the user in before any action runs.
        if (! $user instanceof User) {
            throw new LogicException('The admin stack runs for a signed-in staff user.');
        }

        $runs = Gate::forUser($user)->allows('viewAny', FlowLog::class);
        // Without the flow log the period would still decide which rows show, so it is not the user's to choose.
        $period = ($runs ? FlowActivityPeriod::tryFrom($request->string(self::PERIOD)->toString()) : null) ?? self::DEFAULT_PERIOD;
        $scope  = $overview->assistantScope($user);
        $report = $statistics->reportForCurrentTenant($period->since(CarbonImmutable::now()), $scope);
        $labels = $this->labels($handlers);

        // A row only the flow log knows of (it ran, but no version that can still run contains it) is a run figure too.
        $nodes   = $runs ? $report->usages : array_filter($report->usages, static fn (NodeTypeUsage $usage): bool => $usage->publishedVersions > 0);
        $actions = $runs ? $report->actions : array_filter($report->actions, static fn (ActionUsage $action): bool => $action->publishedVersions > 0);

        return Inertia::render('Console/AdminNodeUsage/Index', [
            'nodes'   => array_values(array_map(fn (NodeTypeUsage $usage): array => $this->nodeRow($usage, $labels, $handlers, $runs), $nodes)),
            'actions' => array_values(array_map(fn (ActionUsage $action): array => $this->actionRow($action, $runs), $actions)),
            'unused'  => null === $scope
                ? array_map(static fn (string $type): array => ['type' => $type, 'label' => $labels[$type] ?? $type], $this->unusedTypes($report, $period, $statistics))
                : null,
            'runs'    => $runs,
            'period'  => $period->value,
            'default' => self::DEFAULT_PERIOD->value,
            'param'   => self::PERIOD,
            'periods' => array_map(static fn (FlowActivityPeriod $case): string => $case->value, FlowActivityPeriod::cases()),
        ]);
    }

    /**
     * The registered types nothing uses, over the whole of what `flow_logs` keeps whatever period is shown: a type that
     * ran two hours ago is not unused because the page shows the last hour.
     *
     * @return list<string>
     */
    private function unusedTypes(NodeUsageReport $report, FlowActivityPeriod $period, NodeUsageStatisticsInterface $statistics): array
    {
        if (self::DEFAULT_PERIOD === $period) {
            return $report->unusedHandlerTypes;
        }

        return $statistics->reportForCurrentTenant(self::DEFAULT_PERIOD->since(CarbonImmutable::now()))->unusedHandlerTypes;
    }

    /**
     * Each registered type's label, as its latest version names it.
     *
     * @return array<string, string>
     */
    private function labels(NodeHandlerRegistryInterface $handlers): array
    {
        $labels = [];

        foreach ($handlers->all() as $handler) {
            $labels[$handler->type()] = $handler->label();
        }

        return $labels;
    }

    /**
     * @param  array<string, string>  $labels
     *
     * @return array<string, mixed>
     */
    private function nodeRow(NodeTypeUsage $usage, array $labels, NodeHandlerRegistryInterface $handlers, bool $runs): array
    {
        return [
            'key'         => $usage->key(),
            'type'        => $usage->nodeType,
            'version'     => $usage->nodeVersion,
            'label'       => $labels[$usage->nodeType] ?? null,
            'solution'    => $handlers->ownerOf($usage->nodeType),
            'registered'  => $usage->handlerRegistered,
            'orphaned'    => $usage->isOrphaned(),
            'activeFlows' => $usage->activeFlows,
            'activeNodes' => $usage->activeNodes,
            'versions'    => $usage->publishedVersions,
            ...$this->runFigures($usage->executions, $usage->failures, $usage->lastExecutedAt, $runs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionRow(ActionUsage $action, bool $runs): array
    {
        return [
            'id'          => $action->actionId,
            'solution'    => $action->solutionId,
            'registered'  => $action->registered,
            'activeFlows' => $action->activeFlows,
            'versions'    => $action->publishedVersions,
            ...$this->runFigures($action->executions, $action->failures, $action->lastExecutedAt, $runs),
        ];
    }

    /**
     * The run figures, or `null` each for a user who may not list the flow log.
     *
     * @return array{executions: int|null, failures: int|null, lastRunAt: string|null}
     */
    private function runFigures(int $executions, int $failures, ?CarbonImmutable $lastRunAt, bool $runs): array
    {
        return [
            'executions' => $runs ? $executions : null,
            'failures'   => $runs ? $failures : null,
            'lastRunAt'  => $runs ? $lastRunAt?->toIso8601String() : null,
        ];
    }
}
