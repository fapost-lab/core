<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\ValueObjects\ReleaseRefusal;
use App\Domains\Tenancy\ValueObjects\TenantProvisioningState;
use Carbon\CarbonInterface;
use Closure;

/**
 * The real landlord repository with hooks: a closure per method name runs before the real call and
 * may throw, or do something else the test needs (for example insert a competing row).
 */
final class FailingTenantRepository implements TenantRepositoryInterface
{
    private TenantRepository $inner;

    /** @var array<string, Closure(): void> */
    private array $after = [];

    /**
     * @param  array<string, Closure(): void>  $before
     */
    public function __construct(private array $before = [])
    {
        $this->inner = new TenantRepository();
    }

    /**
     * @param  Closure(): void  $hook
     */
    public function before(string $method, Closure $hook): void
    {
        $this->before[$method] = $hook;
    }

    public function existsAny(): bool
    {
        $this->hook(__FUNCTION__);

        return $this->inner->existsAny();
    }

    public function findById(string $id): ?TenantInterface
    {
        $this->hook(__FUNCTION__);

        return $this->inner->findById($id);
    }

    public function findBySlug(string $slug): ?TenantInterface
    {
        $this->hook(__FUNCTION__);

        return $this->inner->findBySlug($slug);
    }

    public function getById(string $id): TenantInterface
    {
        $this->hook(__FUNCTION__);

        return $this->inner->getById($id);
    }

    public function findAllActive(): array
    {
        $this->hook(__FUNCTION__);

        return $this->inner->findAllActive();
    }

    public function findBySlugPrefix(string $prefix): array
    {
        $this->hook(__FUNCTION__);

        return $this->inner->findBySlugPrefix($prefix);
    }

    public function save(TenantInterface $tenant): void
    {
        $this->hook(__FUNCTION__);

        $this->inner->save($tenant);
    }

    public function delete(TenantInterface $tenant): void
    {
        $this->hook(__FUNCTION__);

        $this->inner->delete($tenant);
    }

    public function isSlugOrSchemaTaken(string $slug, string $schemaName): bool
    {
        $this->hook(__FUNCTION__);

        $result = $this->inner->isSlugOrSchemaTaken($slug, $schemaName);
        $this->afterHook(__FUNCTION__);

        return $result;
    }

    public function findByReservationKey(string $reservationKey): ?TenantInterface
    {
        $this->hook(__FUNCTION__);

        return $this->inner->findByReservationKey($reservationKey);
    }

    public function provisioningState(string $id): ?TenantProvisioningState
    {
        $this->hook(__FUNCTION__);

        return $this->inner->provisioningState($id);
    }

    public function acquireProvisioningLease(string $id, CarbonInterface $now, CarbonInterface $until): bool
    {
        $this->hook(__FUNCTION__);

        return $this->inner->acquireProvisioningLease($id, $now, $until);
    }

    public function extendProvisioningLease(string $id, CarbonInterface $heldUntil, CarbonInterface $until): bool
    {
        $this->hook(__FUNCTION__);

        return $this->inner->extendProvisioningLease($id, $heldUntil, $until);
    }

    public function markSchemaClaimed(string $id, CarbonInterface $heldUntil, CarbonInterface $now): bool
    {
        $this->hook(__FUNCTION__);

        return $this->inner->markSchemaClaimed($id, $heldUntil, $now);
    }

    public function markProvisioningFailed(string $id, CarbonInterface $heldUntil, CarbonInterface $now, string $error): bool
    {
        $this->hook(__FUNCTION__);

        return $this->inner->markProvisioningFailed($id, $heldUntil, $now, $error);
    }

    public function activate(string $id, CarbonInterface $heldUntil): bool
    {
        $this->hook(__FUNCTION__);

        return $this->inner->activate($id, $heldUntil);
    }

    public function reserve(TenantInterface $tenant): void
    {
        $this->hook(__FUNCTION__);

        $this->inner->reserve($tenant);
    }

    public function releaseUnclaimedPending(string $id, CarbonInterface $now): ?ReleaseRefusal
    {
        $this->hook(__FUNCTION__);

        return $this->inner->releaseUnclaimedPending($id, $now);
    }

    public function transaction(Closure $callback): mixed
    {
        $this->hook(__FUNCTION__);

        return $this->inner->transaction($callback);
    }

    public function lockSlugClaims(): void
    {
        $this->hook(__FUNCTION__);

        $this->inner->lockSlugClaims();
    }

    public function findForRename(string $id): ?TenantProvisioningState
    {
        $this->hook(__FUNCTION__);

        return $this->inner->findForRename($id);
    }

    public function isSlugClaimedByOther(string $slug, string $schemaName, string $exceptTenantId): bool
    {
        $this->hook(__FUNCTION__);

        return $this->inner->isSlugClaimedByOther($slug, $schemaName, $exceptTenantId);
    }

    public function renameSlug(TenantInterface $tenant, string $newSlug, CarbonInterface $now): bool
    {
        $this->hook(__FUNCTION__);

        return $this->inner->renameSlug($tenant, $newSlug, $now);
    }

    public function addFormerSlug(string $slug, string $tenantId, ?CarbonInterface $redirectUntil, CarbonInterface $now): void
    {
        $this->hook(__FUNCTION__);

        $this->inner->addFormerSlug($slug, $tenantId, $redirectUntil, $now);
    }

    public function removeFormerSlug(string $slug, string $tenantId): void
    {
        $this->hook(__FUNCTION__);

        $this->inner->removeFormerSlug($slug, $tenantId);
    }

    public function findByFormerSlug(string $slug, CarbonInterface $now): ?TenantInterface
    {
        $this->hook(__FUNCTION__);

        return $this->inner->findByFormerSlug($slug, $now);
    }

    /**
     * @param  Closure(): void  $hook  runs after the real call returned
     */
    public function after(string $method, Closure $hook): void
    {
        $this->after[$method] = $hook;
    }

    private function afterHook(string $method): void
    {
        if (isset($this->after[$method])) {
            ($this->after[$method])();
        }
    }

    private function hook(string $method): void
    {
        if (isset($this->before[$method])) {
            ($this->before[$method])();
        }
    }
}
