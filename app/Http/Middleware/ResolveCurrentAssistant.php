<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for the console: resolves the `assistant` route parameter (a ULID, or an
 * already bound model) in the current tenant schema and makes it the current assistant.
 *
 * A missing assistant and one the user may not view are both answered 404, never 403: the
 * response must not reveal that an assistant exists. This matches the assistant panel.
 * Run it after the `auth` and `tenant` middleware.
 */
final readonly class ResolveCurrentAssistant
{
    public function __construct(
        private CurrentAssistantInterface $currentAssistant,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $assistant = $this->find($request->route('assistant'));

        if (null === $assistant || Gate::forUser($request->user())->denies('view', $assistant)) {
            abort(404);
        }

        $this->currentAssistant->set($assistant);

        return $next($request);
    }

    private function find(mixed $parameter): ?Assistant
    {
        if ($parameter instanceof Assistant) {
            return $parameter;
        }

        // Keys are ULIDs stored as `uuid` (ADR-03); anything else in the URL is not an assistant, and
        // Postgres would reject it as invalid uuid syntax instead of finding nothing.
        if (! is_string($parameter) || ! Str::isUuid($parameter)) {
            return null;
        }

        return Assistant::query()->find($parameter);
    }
}
