<?php

declare(strict_types=1);

namespace App\Http\Controllers\Filament;

use App\Filament\Assistant\Pages\AssistantDashboard;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * Deterministic landing for {@see \App\Providers\Filament\AssistantPanelProvider}: always sends
 * {@code GET assistant/{tenant}/} to the explicit dashboard page (registered before Filament's default home route).
 */
final class AssistantPanelHomeController
{
    public function __invoke(): RedirectResponse
    {
        $tenant = Filament::getTenant();

        return redirect()->to(AssistantDashboard::getUrl(tenant: $tenant));
    }
}
