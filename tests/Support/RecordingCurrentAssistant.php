<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\CurrentAssistant;

/**
 * Wraps the real {@see CurrentAssistant} and remembers every assistant set on it. A request runs
 * inside a tenant scope whose restore hook resets the current assistant, so what a request set
 * is no longer visible once it has returned.
 */
final class RecordingCurrentAssistant implements CurrentAssistantInterface
{
    /** @var list<Assistant> */
    public array $assistantsSet = [];

    private readonly CurrentAssistant $inner;

    public function __construct()
    {
        $this->inner = new CurrentAssistant();
    }

    public function set(Assistant $assistant): void
    {
        $this->assistantsSet[] = $assistant;
        $this->inner->set($assistant);
    }

    public function get(): Assistant
    {
        return $this->inner->get();
    }

    public function isResolved(): bool
    {
        return $this->inner->isResolved();
    }

    public function reset(): void
    {
        $this->inner->reset();
    }
}
