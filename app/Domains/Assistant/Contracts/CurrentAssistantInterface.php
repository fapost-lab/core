<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Contracts;

use App\Domains\Assistant\Exceptions\CurrentAssistantNotResolvedException;
use App\Domains\Assistant\Models\Assistant;

/**
 * The assistant the current request or job works on. It is set explicitly: by the console middleware, by the panel
 * middleware, or by a job from its payload. Platform (schema) tenant remains
 * {@see \App\Domains\Tenancy\Contracts\TenantContextInterface}.
 */
interface CurrentAssistantInterface
{
    /**
     * Set the current assistant for this request or job.
     */
    public function set(Assistant $assistant): void;

    /**
     * @throws CurrentAssistantNotResolvedException
     */
    public function get(): Assistant;

    public function isResolved(): bool;

    /**
     * Forget the current assistant.
     */
    public function reset(): void;
}
