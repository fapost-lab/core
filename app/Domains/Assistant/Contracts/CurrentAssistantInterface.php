<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Contracts;

use App\Domains\Assistant\Exceptions\CurrentAssistantNotResolvedException;
use App\Domains\Assistant\Models\Assistant;

/**
 * UI-scoped assistant for the assistant Filament panel: resolved from Filament tenancy when present, with an optional
 * override for tests. Platform (schema) tenant remains {@see \App\Domains\Tenancy\Contracts\TenantContextInterface}.
 */
interface CurrentAssistantInterface
{
    /**
     * Provide an explicit assistant override for this request/UI session.
     */
    public function set(Assistant $assistant): void;

    /**
     * @throws CurrentAssistantNotResolvedException
     */
    public function get(): Assistant;

    public function isResolved(): bool;

    /**
     * Clear the explicit override.
     */
    public function reset(): void;
}
