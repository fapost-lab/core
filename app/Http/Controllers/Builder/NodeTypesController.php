<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Http\Controllers\Controller;
use FAPost\Foundation\Contracts\NodeHandlerInterface;
use Illuminate\Http\JsonResponse;

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

    public function index(NodeHandlerRegistryInterface $registry): JsonResponse
    {
        $types = collect($registry->all())
            ->map(fn (NodeHandlerInterface $handler): array => [
                'type'          => $handler->type(),
                'version'       => $handler->version(),
                'label'         => $handler->label(),
                'category'      => $handler->category(),
                'config_schema' => $handler->configSchema(),
                // false → builder keeps the type for rendering/config lookup but
                // hides it from the insertable palette.
                'palette' => ! in_array($handler->type(), self::AUTO_MANAGED_TYPES, true),
            ])
            ->values()
            // Annotation nodes (e.g. comment) have no handler — surface them in
            // the palette manually so the builder can drop them on the canvas.
            ->push([
                'type'          => AnnotationNodeTypes::COMMENT,
                'version'       => 1,
                'label'         => 'Comment',
                'category'      => 'Annotation',
                'config_schema' => (object) [],
                'annotation'    => true,
                'palette'       => true,
            ]);

        return response()->json(['data' => $types]);
    }
}
