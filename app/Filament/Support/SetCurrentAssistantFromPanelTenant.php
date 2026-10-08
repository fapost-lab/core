<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant middleware of the assistant panel: copies the panel tenant (the assistant) into
 * {@see CurrentAssistantInterface}, the only source domain code reads the assistant from.
 *
 * Registered as persistent so Livewire update requests (form saves) replay it too.
 */
final readonly class SetCurrentAssistantFromPanelTenant
{
    public function __construct(
        private CurrentAssistantInterface $currentAssistant,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Assistant) {
            $this->currentAssistant->set($tenant);
        }

        return $next($request);
    }
}
