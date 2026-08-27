<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the operational assistant for the assistant Filament panel: query param {@see self::QUERY_PARAM} first,
 * then session fallback. Sets {@see CurrentAssistantInterface}. Does not call
 * {@see \Filament\Facades\Filament::setTenant()} — that would make Filament append a {@code tenant} query parameter to
 * generated URLs; assistant identity is {@code assistant} only.
 */
final readonly class ResolveAssistantMiddleware
{
    public const QUERY_PARAM = 'assistant';

    public const SESSION_KEY = 'assistant_panel_assistant_id';

    public function __construct(
        private CurrentAssistantInterface $currentAssistant,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $fromQuery = $request->query(self::QUERY_PARAM);
        $id        = (is_string($fromQuery) && '' !== $fromQuery)
            ? $fromQuery
            : session(self::SESSION_KEY);

        if (null === $id || '' === $id) {
            abort(404);
        }

        $platformTenant = $this->tenantContext->get();

        $assistant = Assistant::query()
            ->whereKey($id)
            ->where('tenant_id', $platformTenant->getId())
            ->first();

        if (null === $assistant) {
            session()->forget(self::SESSION_KEY);

            abort(404);
        }

        Gate::authorize('view', $assistant);

        if ($request->has(self::QUERY_PARAM)) {
            session([self::SESSION_KEY => (string)$assistant->getKey()]);
        }

        $this->currentAssistant->set($assistant);

        return $next($request);
    }

    private function shouldSkip(Request $request): bool
    {
        return $request->routeIs('filament.assistant.auth.login');
    }
}
