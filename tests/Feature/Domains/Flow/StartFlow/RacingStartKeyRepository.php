<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\StartFlow;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Models\FlowSession;
use Closure;

/**
 * Hides the row a racing duplicate inserts from the first two look-ups by start key (before and under the lock), as
 * when the duplicate lands right before this job's insert.
 */
final class RacingStartKeyRepository implements FlowSessionRepositoryInterface
{
    private int $lookups = 0;

    public function __construct(
        private readonly FlowSessionRepositoryInterface $real,
        private readonly Closure $race,
    ) {
    }

    public function findActiveForContact(Contact $contact, string $assistantId): ?FlowSession
    {
        return $this->real->findActiveForContact($contact, $assistantId);
    }

    public function findById(string $sessionId): ?FlowSession
    {
        return $this->real->findById($sessionId);
    }

    public function findByFireKey(string $triggerId, string $fireKey): ?FlowSession
    {
        return $this->real->findByFireKey($triggerId, $fireKey);
    }

    public function findByStartKey(string $startKey): ?FlowSession
    {
        if (++$this->lookups <= 2) {
            if (2 === $this->lookups) {
                ($this->race)();
            }

            return null;
        }

        return $this->real->findByStartKey($startKey);
    }

    public function create(array $attributes): FlowSession
    {
        return $this->real->create($attributes);
    }

    public function cancel(FlowSession $session): void
    {
        $this->real->cancel($session);
    }
}
