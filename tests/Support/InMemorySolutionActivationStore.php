<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Closure;

/**
 * A store that keeps one list per tenant id, read from the tenant context, standing in for "the schema
 * of the tenant the caller is switched into". SQLite has no schemas, so this is how a test gives two
 * tenants different answers. Counts its reads.
 */
final class InMemorySolutionActivationStore implements SolutionActivationStoreInterface
{
    /** @var array<string, list<string>> */
    public array $active = [];

    public int $reads = 0;

    /**
     * @param  Closure(): TenantContextInterface  $tenantContext  resolved on each use: the context is scoped and a worker replaces it between jobs
     */
    public function __construct(private readonly Closure $tenantContext)
    {
    }

    public function activeIds(): array
    {
        ++$this->reads;

        $ids = $this->active[($this->tenantContext)()->get()->getId()] ?? [];
        sort($ids);

        return $ids;
    }

    public function all(): array
    {
        return [];
    }

    public function events(string $solutionId): array
    {
        return [];
    }

    public function change(string $solutionId, SolutionActivationStatus $target, ActivationActor $actor, ?string $reason): ActivationChange
    {
        $tenantId = ($this->tenantContext)()->get()->getId();
        $current  = $this->active[$tenantId] ?? [];
        $has      = in_array($solutionId, $current, true);

        if (SolutionActivationStatus::Active === $target) {
            if ($has) {
                return ActivationChange::Unchanged;
            }

            $this->active[$tenantId] = [...$current, $solutionId];

            return ActivationChange::Activated;
        }

        if (!$has) {
            return ActivationChange::Unchanged;
        }

        $this->active[$tenantId] = array_values(array_diff($current, [$solutionId]));

        return ActivationChange::Deactivated;
    }
}
