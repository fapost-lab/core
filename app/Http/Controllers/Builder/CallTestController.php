<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Call\CallTester;
use App\Http\Controllers\Controller;
use App\Http\Requests\CallTestRequest;
use Illuminate\Http\JsonResponse;

/**
 * Runs a `call` node configuration live from the builder and returns the
 * response so the author can inspect its shape for result_mapping.
 */
final class CallTestController extends Controller
{
    public function __invoke(CallTestRequest $request, CallTester $tester): JsonResponse
    {
        /** @var array<string, mixed> $config */
        $config = $request->validated('config');
        /** @var array<string, mixed> $sample */
        $sample = $request->validated('sample') ?? [];

        return response()->json(['data' => $tester->run($config, $sample)]);
    }
}
