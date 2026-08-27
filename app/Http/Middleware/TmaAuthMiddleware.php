<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary Telegram initData middleware for local development.
 *
 * Full Telegram HMAC verification is intentionally deferred for a dedicated task.
 */
final class TmaAuthMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'Telegram initData verification is not implemented.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
