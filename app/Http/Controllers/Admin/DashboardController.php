<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastReport;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowActivityPeriod;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowRunAnalytics;
use App\Domains\Flow\Services\TenantFlowActivity;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\TenantUsageOverview;
use App\Http\Controllers\Controller;
use App\Http\Shell\AdminOverview;
use App\Http\Shell\UsagePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The admin panel's home, inside the console shell's admin mode: the tenant's figures and the flow activity of the
 * last two weeks, in place of Filament's `StatsOverview` and `FlowActivityChart` widgets.
 *
 * Every signed-in staff user opens it, as they opened Filament's dashboard. Filament showed every figure to all of
 * them; here a figure is given only to a user who may list that kind of record (`viewAny` of its policy), and is
 * `null` otherwise. For anyone but an administrator, assistants, channels, flows, waiting sessions and the activity
 * count only the assistants the user may list; contacts and staff are the tenant's. The limits widget is for whoever
 * may open the usage page (`manage_settings`), and is computed once per page view, not on the polling refresh. The
 * latest broadcast runs are for whoever may manage broadcasts, in the same assistant scope, and refresh with the figures.
 *
 * The runs per flow (`flowRuns`) are for whoever may list flow sessions, over the period of `?runs_period` (one of
 * {@see FlowActivityPeriod}, its default otherwise), narrowed to the same assistants as the other figures.
 */
final class DashboardController extends Controller
{
    /**
     * How often the page refreshes its figures, as the Filament widgets polled.
     */
    public const int POLL_SECONDS = 60;

    /**
     * The query parameter that chooses the period of the runs per flow.
     */
    public const string RUNS_PERIOD = 'runs_period';

    public function __invoke(Request $request, AdminOverview $overview, TenantFlowActivity $flows, FlowRunAnalytics $runs, TenantUsageOverview $usage, UsagePresenter $usagePresenter, BroadcastReport $broadcasts): Response
    {
        $user = $request->user();

        // The `admin` stack signs the user in before any action runs.
        if (! $user instanceof User) {
            throw new LogicException('The admin stack runs for a signed-in staff user.');
        }

        $sees   = static fn (string $model): bool => Gate::forUser($user)->allows('viewAny', $model);
        $scope  = $overview->assistantScope($user);
        $period = FlowActivityPeriod::tryFrom($request->string(self::RUNS_PERIOD)->toString()) ?? FlowActivityPeriod::default();

        return Inertia::render('Console/AdminDashboard/Index', [
            'stats' => [
                'assistants' => $sees(Assistant::class) ? $overview->assistants($user) : null,
                'contacts'   => $sees(Contact::class) ? $overview->contacts() : null,
                'channels'   => $sees(Channel::class) ? $overview->channels($scope) : null,
                'flows'      => $sees(FlowDraft::class) ? ['published' => $flows->publishedFlows($scope)] : null,
                'sessions'   => $sees(FlowSession::class) ? ['waiting' => $flows->waitingSessions($scope)] : null,
                'staff'      => $sees(User::class) ? $overview->staff() : null,
            ],
            'activity' => Gate::allows('viewAny', FlowLog::class) ? $flows->daily(CarbonImmutable::now(), $scope) : null,
            // The latest runs, for whoever may manage broadcasts, in the assistants they may open; each links to its report.
            'broadcasts' => $sees(Broadcast::class)
                ? $broadcasts->latest($scope)->map(fn (Broadcast $broadcast): array => $this->broadcastRow($broadcast))->values()->all()
                : null,
            'flowRuns' => $sees(FlowSession::class)
                ? [
                    ...$runs->perFlow($period, CarbonImmutable::now(), $scope),
                    'period'  => $period->value,
                    'default' => FlowActivityPeriod::default()->value,
                    'param'   => self::RUNS_PERIOD,
                    'periods' => array_map(static fn (FlowActivityPeriod $case): string => $case->value, FlowActivityPeriod::cases()),
                ]
                : null,
            'pollSeconds' => self::POLL_SECONDS,
            // A closure, so the polling refresh (`only: stats, activity`) does not ask the operator again: one call per view.
            'usage' => $user->can(Permission::ManageSettings->value)
                ? static fn (): array => [
                    ...$usagePresenter->widget($usage->overview(app()->getLocale())),
                    'url' => route('console.admin.usage.index', [], false),
                ]
                : null,
        ]);
    }

    /**
     * One run of the broadcasts card: its figures are the broadcast's own counters, as the broadcasts list shows them.
     *
     * @return array<string, mixed>
     */
    private function broadcastRow(Broadcast $broadcast): array
    {
        return [
            'id'            => (string) $broadcast->getKey(),
            'name'          => $broadcast->name,
            'assistantName' => (string) $broadcast->assistant->name,
            'status'        => $broadcast->status->value,
            'stopReason'    => $broadcast->stop_reason,
            'sent'          => $broadcast->sent_count,
            'total'         => $broadcast->total_recipients,
            'failed'        => $broadcast->failed_count,
            'skipped'       => $broadcast->skipped_count,
            'startedAt'     => ($broadcast->started_at ?? $broadcast->created_at)?->toIso8601String(),
            'reportUrl'     => route('console.broadcasts.show', ['assistant' => $broadcast->assistant_id, 'record' => $broadcast->getKey()], false),
        ];
    }
}
