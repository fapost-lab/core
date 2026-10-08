<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Exceptions\CurrentAssistantNotResolvedException;
use App\Domains\Assistant\Models\Assistant;

/**
 * Holds the assistant the current request or job works on. It knows nothing about any UI: the assistant is set
 * explicitly by the console middleware, by the panel middleware, or by a job from its payload.
 *
 * Contract: {@see CurrentAssistantInterface} lives under {@code Domains/Assistant/Contracts/}; this class is the
 * scoped implementation in {@code Services/}.
 */
final class CurrentAssistant implements CurrentAssistantInterface
{
    private ?Assistant $assistant = null;

    /**
     * Set the current assistant.
     */
    public function set(Assistant $assistant): void
    {
        $this->assistant = $assistant;
    }

    /**
     * Return the assistant that was set.
     *
     * @throws CurrentAssistantNotResolvedException If no assistant was set.
     */
    public function get(): Assistant
    {
        return $this->assistant ?? throw CurrentAssistantNotResolvedException::make();
    }

    /**
     * Whether an assistant has been set.
     */
    public function isResolved(): bool
    {
        return null !== $this->assistant;
    }

    /**
     * Forget the assistant that was set.
     */
    public function reset(): void
    {
        $this->assistant = null;
    }
}
