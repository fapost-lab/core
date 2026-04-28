<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Http\Controllers\Controller;
use FAPost\Foundation\Contracts\NodeHandlerInterface;
use Illuminate\Http\JsonResponse;

final class NodeTypesController extends Controller
{
    public function index(NodeHandlerRegistryInterface $registry): JsonResponse
    {
        $types = collect($registry->all())
            ->map(fn(NodeHandlerInterface $handler): array => [
                'type'          => $handler->type(),
                'version'       => $handler->version(),
                'label'         => $handler->label(),
                'category'      => $handler->category(),
                'config_schema' => $handler->configSchema(),
            ])
            ->values();

        return response()->json(['data' => $types]);
    }
}
