<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\AssistantFlowActivity;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The assistant's home screen: the same figures and links as the Filament dashboard it replaces.
 */
final class DashboardController extends Controller
{
    public function __invoke(CurrentAssistantInterface $currentAssistant, AssistantFlowActivity $activity): Response
    {
        $assistant = $currentAssistant->get();

        Gate::authorize('view', $assistant);

        $id = (string) $assistant->getKey();

        return Inertia::render('Console/Dashboard', [
            'assistant' => [
                'id'            => $id,
                'name'          => (string) $assistant->name,
                'isActive'      => (bool) $assistant->is_active,
                'channelsCount' => $assistant->channels()->count(),
            ],
            'operations' => [
                'liveSessions' => $activity->liveSessions($id),
                'errors24h'    => $activity->errorsLast24Hours($id),
                // A link is offered only where the user may open the list it leads to.
                'sessionsUrl' => Gate::allows('viewAny', FlowSession::class)
                    ? route('filament.assistant.resources.flow-sessions.index', ['tenant' => $id], false)
                    : null,
                'logsUrl' => Gate::allows('viewAny', FlowLog::class)
                    ? route('filament.assistant.resources.flow-logs.index', ['tenant' => $id], false)
                    : null,
            ],
        ]);
    }
}
