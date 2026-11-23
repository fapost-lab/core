<?php

declare(strict_types=1);

namespace Tests\Support\Flow;

use App\Domains\Flow\Webhook\WebhookWaitSlotsInterface;

/**
 * {@see WebhookWaitSlotsInterface} in memory: records what was asked and what is still held.
 */
final class InMemoryWebhookWaitSlots implements WebhookWaitSlotsInterface
{
    public bool $full = false;

    /** @var list<array{int, int, int}> */
    public array $acquired = [];

    /** @var array<string, string> slot => tenant */
    public array $held = [];

    public function acquire(string $tenantId, int $installationMax, int $tenantMax, int $holdSeconds): ?string
    {
        $this->acquired[] = [$installationMax, $tenantMax, $holdSeconds];

        if ($this->full) {
            return null;
        }

        $slot              = 'slot-' . count($this->acquired);
        $this->held[$slot] = $tenantId;

        return $slot;
    }

    public function release(string $tenantId, string $slot): void
    {
        unset($this->held[$slot]);
    }
}
