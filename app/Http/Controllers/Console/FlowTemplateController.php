<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Exceptions\FlowTemplateInUseException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\FlowDraftService;
use App\Domains\Flow\Templates\TemplateUsageFinder;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\DestroyFlowTemplatesRequest;
use App\Http\Requests\Console\FlowTemplateRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Flow templates on the Inertia console: the list, creating one (which ends in the builder) and deleting. A template is
 * a {@see FlowDraft} of kind `template`, so {@see FlowDraftService} does the work and the flow policy and limit apply;
 * a template still placed in a flow cannot be deleted, and this controller words that refusal as a toast.
 */
final class FlowTemplateController extends Controller
{
    public function __construct(
        private readonly FlowDraftService $flows,
        private readonly TemplateUsageFinder $usages,
        private readonly CurrentAssistantInterface $assistant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FlowDraft::class);

        $limit = $this->flows->limit();
        $table = new DataTable(
            sortable: ['name'],
            searchable: ['name'],
            defaultSort: 'name',
        );

        $page = $table->respond(
            $request,
            $this->flows->query(FlowKind::Template),
            fn (FlowDraft $template): array => [
                'id'               => (string) $template->getKey(),
                'flowId'           => $template->flow_id,
                'name'             => $template->name,
                'description'      => $template->description,
                'publishedVersion' => null === $template->getAttribute('published_version') ? null : (int) $template->getAttribute('published_version'),
                'usedBy'           => 0,
                'deleteUrl'        => $this->url('destroy', ['record' => $template->getKey()]),
                'builderUrl'       => $this->builderUrl($template),
            ],
        );

        // The usage of the whole page is counted at once: counting per row loads every flow graph of the assistant
        // for each template.
        $assistant = $this->assistant->get();
        $counts    = $this->usages->usageCounts(
            (string) $assistant->tenant_id,
            (string) $assistant->getKey(),
            array_column($page['rows'], 'flowId'),
        );

        $page['rows'] = array_map(
            static fn (array $row): array => [...$row, 'usedBy' => $counts[$row['flowId']] ?? 0],
            $page['rows'],
        );

        return Inertia::render('Console/FlowTemplates/Index', [
            'table' => $page,
            'limit' => [
                'reached' => $limit->reached,
                'hint'    => $limit->reached && null !== $limit->limit
                    ? trans('assistant.flows.limit.hint', ['current' => $limit->current, 'limit' => $limit->limit])
                    : null,
            ],
            'can' => [
                // The policy lets an administrator through whatever the limit says, so the limit is asked on its own.
                'create' => Gate::allows('create', FlowDraft::class) && ! $limit->reached,
                'delete' => Gate::allows('delete', $this->flows->abilityProbe()),
            ],
            'urls' => [
                'index'       => $this->url('index'),
                'create'      => $this->url('create'),
                'destroyMany' => $this->url('destroy-many'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', FlowDraft::class);

        // The page is closed at the limit, but only on opening: a form saved after another request took the last slot
        // still reaches the service, which refuses with a message.
        abort_if($this->flows->limit()->reached, HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('Console/FlowTemplates/Create', [
            'urls' => [
                'index'  => $this->url('index'),
                'submit' => $this->url('store'),
            ],
        ]);
    }

    public function store(FlowTemplateRequest $request): RedirectResponse|HttpResponse
    {
        try {
            $template = $this->flows->create($request->fields(), FlowKind::Template);
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('assistant.flows.limit.reached_title') . '. ' . $exception->getMessage());

            return redirect()->back(fallback: $this->url('index'));
        }

        // The builder is a separate app, so this is a full navigation, not an Inertia visit.
        return Inertia::location($this->builderUrl($template));
    }

    /*
     * Route parameters reach an action by position, so `$assistantKey` has to be declared ahead of `$record`.
     */
    public function destroy(string $assistantKey, string $record): RedirectResponse
    {
        $template = $this->flows->findForAssistant($record, FlowKind::Template);

        Gate::authorize('delete', $template);

        try {
            $this->flows->delete($template);
        } catch (FlowTemplateInUseException) {
            return $this->backToList(trans('console.flow_templates.delete_blocked'), 'error');
        }

        return $this->backToList(trans('console.flow_templates.deleted'));
    }

    public function destroyMany(DestroyFlowTemplatesRequest $request): RedirectResponse
    {
        $result = $this->flows->deleteMany($request->ids(), FlowKind::Template);

        if (0 === $result['deleted'] && $result['blocked'] > 0) {
            return $this->backToList(trans('console.flow_templates.delete_blocked'), 'error');
        }

        if ($result['blocked'] > 0) {
            return $this->backToList(trans('console.flow_templates.delete_many_blocked', $result));
        }

        return $this->backToList(trans('console.flow_templates.deleted_many', ['count' => $result['deleted']]));
    }

    /**
     * After a change made from the list: the list the user was on, with its search, sort and page (the referer), or
     * the default list when there is none.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToList(string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->back(fallback: $this->url('index'));
    }

    /**
     * The template's page in the builder, by its route name.
     */
    private function builderUrl(FlowDraft $template): string
    {
        return route('builder.flows.show', ['flow' => $template->flow_id], false);
    }

    /**
     * A relative URL of one of this screen's routes, inside the current assistant's console.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        return route('console.flow-templates.' . $action, ['assistant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
