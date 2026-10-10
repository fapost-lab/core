<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Enums\RefusedWork;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\DTO\UsageDecision;
use Fapost\Foundation\Quota\DTO\UsageUnit;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Spends one unit of a per-period limit for the current tenant and says whether it fit.
 *
 * The operator package counts and decides ({@see UsageMeterInterface}); this class is the only way
 * Core reaches it. It checks that the key is a registered per-period key (a mistake of the caller,
 * so it throws), takes the tenant from {@see TenantContextInterface} (callers outside a request run
 * inside a tenant switch) and fails open: an operator outage (its database, its cache, a bug) must
 * not silence every tenant's bots at once, so an answer that cannot be had is reported and counts
 * as allowed, as in {@see TenantAccessStates}.
 */
final readonly class PeriodQuota
{
    public function __construct(
        private LimitRegistryInterface $registry,
        private UsageMeterInterface $meter,
        private TenantContextInterface $context,
        private LimitAnnouncer $announcer,
    ) {
    }

    /**
     * @param  string  $key  a key registered with kind {@see LimitKind::PerPeriod}
     * @param  string  $unitKey  the unit's natural key, the same on every retry of the same unit
     * @param  DateTimeImmutable  $occurredAt  when the unit was used; picks the period
     * @param  RefusedWork|null  $refused  what a refusal turns away, for the admins' notification
     *
     * @throws LogicException when the key is not registered or is not a per-period key
     */
    public function consume(string $key, string $unitKey, DateTimeImmutable $occurredAt, ?RefusedWork $refused = null): UsageDecision
    {
        $definition = $this->registry->find($key);

        if (null === $definition) {
            throw new LogicException(sprintf('Limit "%s" is not registered.', $key));
        }

        if (LimitKind::PerPeriod !== $definition->kind) {
            throw new LogicException(sprintf('Limit "%s" is not a per-period limit.', $key));
        }

        $unit = new UsageUnit($this->context->get()->getId(), $key, $unitKey, $occurredAt);

        try {
            $decision = $this->meter->consume($unit);
        } catch (Throwable $exception) {
            report($exception);

            return UsageDecision::allowed();
        }

        if (! $decision->allowed) {
            // One place for every per-period gate; no unit key, which can name a contact.
            Log::info('quota.volume.refused', [
                'tenant_id' => $unit->tenantId,
                'key'       => $key,
                'limit'     => $decision->limit,
                'used'      => $decision->used,
            ]);

            if (null !== $decision->limit) {
                $this->announcer->refused(
                    $key,
                    LimitKind::PerPeriod,
                    $decision->limit,
                    $decision->used,
                    $refused ?? RefusedWork::Other,
                    $decision->periodEndsAt,
                );
            }
        }

        return $decision;
    }
}
