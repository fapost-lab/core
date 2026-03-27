<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Exceptions\CurrentAssistantNotResolvedException;
use App\Domains\Assistant\Models\Assistant;
use Filament\Facades\Filament;

/**
 * Resolves the assistant from Filament panel tenancy when present; supports an explicit override for tests.
 *
 * Contract: {@see CurrentAssistantInterface} lives under {@code Domains/Assistant/Contracts/}; this class is the scoped implementation in {@code Services/}.
 */
final class CurrentAssistant implements CurrentAssistantInterface
{
    private ?Assistant $override = null;

    public function set(Assistant $assistant): void
    {
        $this->override = $assistant;
    }

    public function get(): Assistant
    {
        $fromFilament = $this->resolveFromFilament();

        if (null !== $fromFilament) {
            return $fromFilament;
        }

        return $this->override ?? throw CurrentAssistantNotResolvedException::make();
    }

    public function isResolved(): bool
    {
        return null !== $this->resolveFromFilament() || null !== $this->override;
    }

    public function reset(): void
    {
        $this->override = null;
    }

    private function resolveFromFilament(): ?Assistant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Assistant ? $tenant : null;
    }
}
