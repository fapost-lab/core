<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class TriggerTypesController extends Controller
{
    /**
     * The trigger types a flow author may pick: the built-in ones, which the builder draws with its own forms, then
     * every registered one with the form its `config_schema` describes. A type of a Solution the tenant has not
     * activated is listed with `available: false`: the builder keeps a saved trigger of it, greyed out, and does not
     * offer it for a new one. A type nobody registers is absent; the builder shows a saved trigger of it as not
     * installed, its config kept.
     */
    public function index(
        TriggerTypeRegistry $registry,
        SolutionAvailabilityInterface $availability,
        InstalledSolutions $solutions,
    ): JsonResponse {
        Gate::authorize('viewAny', FlowDraft::class);

        $entries = [];

        foreach (FlowTriggerType::cases() as $type) {
            $entries[] = [
                'type'           => $type->value,
                'label'          => $type->name,
                'builtin'        => true,
                'config_schema'  => null,
                'payload_fields' => [],
                'available'      => true,
                'solution'       => null,
            ];
        }

        foreach ($registry->types() as $name) {
            $type  = $registry->get($name);
            $owner = $registry->ownerOf($name);

            $entries[] = [
                'type'           => $name,
                'label'          => $type->label(),
                'builtin'        => false,
                'config_schema'  => $type->configSchema(),
                'payload_fields' => $type->payloadFields(),
                'available'      => null === $owner || $availability->isAvailable($owner),
                'solution'       => null === $owner ? null : ['id' => $owner, 'name' => $solutions->find($owner)?->name ?? $owner],
            ];
        }

        return response()->json(['data' => $entries]);
    }
}
