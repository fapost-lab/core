<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\TenantFlowActivity;
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
 * The admin panel's home, inside the console shell's admin mode: the tenant's figures and the flow activity of the
 * last two weeks, in place of Filament's `StatsOverview` and `FlowActivityChart` widgets.
 *
 * Every signed-in staff user opens it, as they opened Filament's dashboard. Filament showed every figure to all of
 * them; here a figure is given only to a user who may list that kind of record (`viewAny` of its policy), and is
 * `null` otherwise.
 */
final class DashboardController extends Controller
{
    /**
     * How often the page refreshes its figures, as the Filament widgets polled.
     */
    public const int POLL_SECONDS = 60;

    public function __invoke(Request $request, AdminOverview $overview, TenantFlowActivity $flows): Response
    {
        $user = $request->user();

        // The `admin` stack signs the user in before any action runs.
        if (! $user instanceof User) {
            throw new LogicException('The admin stack runs for a signed-in staff user.');
        }

        $sees = static fn (string $model): bool => Gate::forUser($user)->allows('viewAny', $model);

        return Inertia::render('Console/AdminDashboard/Index', [
            'stats' => [
                'assistants' => $sees(Assistant::class) ? $overview->assistants($user) : null,
                'contacts'   => $sees(Contact::class) ? $overview->contacts() : null,
                'channels'   => $sees(Channel::class) ? $overview->channels() : null,
                'flows'      => $sees(FlowDraft::class) ? ['published' => $flows->publishedFlows()] : null,
                'sessions'   => $sees(FlowSession::class) ? ['waiting' => $flows->waitingSessions()] : null,
                'staff'      => $sees(User::class) ? $overview->staff() : null,
            ],
            'activity'    => Gate::allows('viewAny', FlowLog::class) ? $flows->daily(CarbonImmutable::now()) : null,
            'pollSeconds' => self::POLL_SECONDS,
        ]);
    }
}
