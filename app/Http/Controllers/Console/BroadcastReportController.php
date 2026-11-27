<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Broadcasting\Enums\BroadcastOrigin;
use App\Domains\Broadcasting\Enums\RecipientFailureReason;
use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Models\BroadcastRecipient;
use App\Domains\Broadcasting\Services\BroadcastReport;
use App\Domains\Broadcasting\Services\BroadcastService;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Services\FlowDraftService;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The report of one broadcast on the Inertia console: what its run did, by outcome, and each recipient with theirs.
 *
 * It only reads, behind the same policy as the broadcasts list (`view`: the broadcast permission and access to the
 * broadcast's assistant). A recipient's failure is shown as a {@see RecipientFailureReason}, never as the text the
 * send path stored: that text is the sender's exception message and can carry a provider URL with a secret in it.
 * There is no "delivered" figure: delivery statuses are not taken in from the providers yet.
 */
final class BroadcastReportController extends Controller
{
    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly BroadcastReport $report,
        private readonly CurrentAssistantInterface $assistant,
        private readonly FlowDraftService $flowDrafts,
    ) {
    }

    /*
     * Route parameters reach an action by position, not by name, so `$assistantKey` (the assistant in the URL, already
     * resolved by the console stack) has to be declared ahead of `$record`.
     */
    public function show(Request $request, string $assistantKey, string $record): Response
    {
        $broadcast = $this->broadcasts->findForAssistant($record);

        Gate::authorize('view', $broadcast);

        $canOpenContacts = Gate::allows('viewAny', Contact::class);

        $table = new DataTable(
            // Not `sent_at`: an unsent recipient has none, and the engines put nulls at opposite ends.
            sortable: ['updated_at'],
            searchable: [],
            defaultSort: '-updated_at',
            filters: [
                'status' => fn (Builder $query, string $value): bool => $this->report->filterByStatus($query, $value),
            ],
        );

        return Inertia::render('Console/Broadcasts/Show', [
            'broadcast' => $this->summary($broadcast),
            'outcomes'  => $this->report->outcomes($broadcast),
            'table'     => $table->respond(
                $request,
                $this->report->recipients($broadcast),
                fn (BroadcastRecipient $recipient): array => $this->row($recipient, $broadcast, $canOpenContacts),
            ),
            'statuses' => array_map(static fn (RecipientStatus $status): string => $status->value, RecipientStatus::cases()),
            'urls'     => [
                'index' => $this->url('index'),
                'show'  => $this->url('show', ['record' => $broadcast->getKey()]),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Broadcast $broadcast): array
    {
        $flowId   = BroadcastOrigin::Automation === $broadcast->origin ? $broadcast->flow_id : null;
        $flowName = null !== $flowId ? ($this->flowDrafts->namesByFlowId([$flowId])[$flowId] ?? null) : null;

        return [
            'id'     => (string) $broadcast->getKey(),
            'name'   => $broadcast->name,
            'target' => $broadcast->target_type->value,
            'status' => $broadcast->status->value,
            // Why a failed or stopped run ended, as the list shows it.
            'stopReason' => $broadcast->stop_reason,
            'origin'     => $broadcast->origin->value,
            'flowName'   => $flowName,
            'flowUrl'    => null !== $flowName && Gate::allows('view', $this->flowDrafts->abilityProbe())
                ? route('builder.flows.show', ['flow' => $flowId])
                : null,
            'createdAt'   => $broadcast->created_at?->toIso8601String(),
            'startedAt'   => $broadcast->started_at?->toIso8601String(),
            'completedAt' => $broadcast->completed_at?->toIso8601String(),
        ];
    }

    /**
     * One recipient: who it was for, how it ended and, when it was not sent, why, as a category.
     *
     * @return array<string, mixed>
     */
    private function row(BroadcastRecipient $recipient, Broadcast $broadcast, bool $canOpenContacts): array
    {
        $contact = $recipient->contact;
        $reason  = RecipientFailureReason::of($recipient->status, $recipient->error, $broadcast->status, $broadcast->stop_reason);

        return [
            'id'         => (string) $recipient->getKey(),
            'contact'    => $contact?->displayName(),
            'externalId' => $contact?->external_id,
            'platform'   => $contact?->platform,
            'status'     => $recipient->status->value,
            'reason'     => $reason?->value,
            'sentAt'     => $recipient->sent_at?->toIso8601String(),
            // When the outcome was recorded; for a queued recipient, when it was queued.
            'updatedAt'  => $recipient->updated_at?->toIso8601String(),
            'contactUrl' => null !== $contact && $canOpenContacts
                ? route('console.contacts.show', ['assistant' => (string) $this->assistant->get()->getKey(), 'record' => $contact->getKey()], false)
                : null,
        ];
    }

    /**
     * A relative URL of one of the broadcasts screen's routes, inside the current assistant's console.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        return route('console.broadcasts.' . $action, ['assistant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
