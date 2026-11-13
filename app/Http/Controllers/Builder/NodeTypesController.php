<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Http\Controllers\Controller;
use Fapost\Foundation\Flow\Contracts\StateNamespacesInterface;
use Fapost\Foundation\Flow\Enums\NodeCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class NodeTypesController extends Controller
{
    /**
     * Node types that exist in the registry but must NOT appear in the builder
     * palette — they are created and managed automatically by the builder
     * (e.g. `loop_end` is auto-appended to a loop branch, never added by hand).
     *
     * @var list<string>
     */
    private const array AUTO_MANAGED_TYPES = ['loop_end'];

    private const string ANNOTATION_GROUP = 'Annotation';

    /**
     * Core's node types and the types of every installed Solution. A type of a Solution the tenant has not
     * activated is listed with `available: false` and `palette: false`: the builder still draws saved nodes of
     * it, with a note, but never offers it for insertion. Types of a Solution that is not installed are absent.
     */
    public function index(
        NodeHandlerRegistryInterface $registry,
        SolutionAvailabilityInterface $availability,
        InstalledSolutions $solutions,
        StateNamespacesInterface $stateNamespaces,
    ): JsonResponse {
        Gate::authorize('viewAny', FlowDraft::class);

        $groups  = [];
        $entries = [];

        foreach ($registry->all() as $handler) {
            $type       = $handler->type();
            $owner      = $registry->ownerOf($type);
            $solution   = null !== $owner ? ['id' => $owner, 'name' => $solutions->find($owner)?->name ?? $owner] : null;
            $category   = $handler->category();
            $isOwnGroup = null !== $owner && $category === $owner;
            $group      = [
                'key'   => $category,
                'label' => $isOwnGroup ? $solution['name'] : $category,
                'owner' => $isOwnGroup ? $owner : null,
            ];
            $available = null === $owner || $availability->isAvailable($owner);

            $groups[$group['key']] = $group;
            $entries[]             = [
                'type'          => $type,
                'version'       => $handler->version(),
                'label'         => $handler->label(),
                'category'      => $category,
                'config_schema' => $handler->configSchema(),
                // false → builder keeps the type for rendering/config lookup but
                // hides it from the insertable palette.
                'palette'   => $available && ! in_array($type, self::AUTO_MANAGED_TYPES, true),
                'group'     => $group,
                'available' => $available,
                'solution'  => $solution,
            ];
        }

        // Annotation nodes (e.g. comment) have no handler — surface them in
        // the palette manually so the builder can drop them on the canvas.
        $annotation = ['key' => self::ANNOTATION_GROUP, 'label' => self::ANNOTATION_GROUP, 'owner' => null];
        $entries[]  = [
            'type'          => AnnotationNodeTypes::COMMENT,
            'version'       => 1,
            'label'         => 'Comment',
            'category'      => self::ANNOTATION_GROUP,
            'config_schema' => AnnotationNodeTypes::commentConfigSchema(),
            'annotation'    => true,
            'palette'       => true,
            'group'         => $annotation,
            'available'     => true,
            'solution'      => null,
        ];

        return response()->json([
            'data'   => $entries,
            'groups' => $this->orderedGroups($groups, $annotation),
            // State namespaces of the Solutions active for the tenant, offered by the pickers as free-path sources.
            'state_namespaces' => $this->solutionStateNamespaces($stateNamespaces, $availability),
        ]);
    }

    /**
     * @return list<array{key: string, owner: string}>
     */
    private function solutionStateNamespaces(
        StateNamespacesInterface $stateNamespaces,
        SolutionAvailabilityInterface $availability,
    ): array {
        $out = [];

        foreach ($stateNamespaces->all() as $namespace) {
            $owner = $stateNamespaces->ownerOf($namespace);

            if (null !== $owner && $availability->isAvailable($owner)) {
                $out[] = ['key' => $namespace, 'owner' => $owner];
            }
        }

        return $out;
    }

    /**
     * Core groups in the declared order of {@see NodeCategory}, then Solution groups by label, then the
     * annotation group.
     *
     * @param  array<string, array{key: string, label: string, owner: string|null}>  $groups
     * @param  array{key: string, label: string, owner: null}  $annotation
     *
     * @return list<array{key: string, label: string, owner: string|null}>
     */
    private function orderedGroups(array $groups, array $annotation): array
    {
        $core = [];

        foreach (NodeCategory::cases() as $category) {
            if (isset($groups[$category->value])) {
                $core[] = $groups[$category->value];
                unset($groups[$category->value]);
            }
        }

        $solutionGroups = array_values($groups);
        usort($solutionGroups, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return [...$core, ...$solutionGroups, $annotation];
    }
}
