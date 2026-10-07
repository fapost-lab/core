<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use LogicException;

/**
 * Decides whether the current tenant may create one more record under a registered limit key.
 *
 * The operator package answers how much is allowed ({@see TenantLimitsInterface}); the caller
 * counts what exists, because records live in the tenant's own schema. The tenant is the one in
 * {@see TenantContextInterface}, so callers outside a request run inside a tenant switch.
 */
final readonly class RecordQuota implements RecordQuotaInterface
{
    public function __construct(
        private LimitRegistryInterface $registry,
        private TenantLimitsInterface $limits,
        private TenantContextInterface $context,
    ) {
    }

    public function canCreate(string $key, int $current): bool
    {
        $limit = $this->limit($key);

        return null === $limit || $current < $limit;
    }

    public function assertCanCreate(string $key, int $current): void
    {
        $limit = $this->limit($key);

        if (null !== $limit && $current >= $limit) {
            throw new RecordLimitReachedException($key, $this->registry->find($key)->label ?? $key, $limit, $current);
        }
    }

    /**
     * The current tenant's limit for the key, or null when there is none.
     *
     * @throws LogicException when the key is not registered
     */
    public function limit(string $key): ?int
    {
        if (null === $this->registry->find($key)) {
            throw new LogicException(sprintf('Limit "%s" is not registered.', $key));
        }

        return $this->limits->limitFor($this->context->get()->getId(), $key);
    }
}
