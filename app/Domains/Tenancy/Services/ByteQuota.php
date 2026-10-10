<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Enums\LimitKind;
use LogicException;
use Throwable;

/**
 * Tells how many stored bytes the current tenant may hold under a registered byte limit key.
 *
 * Like {@see RecordQuota} the operator package answers how much is allowed
 * ({@see TenantLimitsInterface}) and the caller counts what exists, because the bytes live in the
 * tenant's own storage. Unlike a record, whether new bytes fit is "used + incoming <= limit", so
 * the comparison stays with the caller, which also knows the source of the bytes.
 *
 * An operator failure is the caller's choice: a person's upload fails closed (the exception
 * propagates, the person retries), an inbound file fails open (the failure is reported and there is
 * no limit for that call), as in {@see PeriodQuota}.
 */
final readonly class ByteQuota
{
    public function __construct(
        private LimitRegistryInterface $registry,
        private TenantLimitsInterface $limits,
        private TenantContextInterface $context,
    ) {
    }

    /**
     * The current tenant's limit for the key in bytes, or null when there is none.
     *
     * @param  string  $key  a key registered with kind {@see LimitKind::Bytes}
     * @param  bool  $failOpen  report an operator failure and answer "no limit" instead of throwing
     *
     * @throws LogicException when the key is not registered or is not a byte key
     */
    public function limit(string $key, bool $failOpen = false): ?int
    {
        $definition = $this->registry->find($key);

        if (null === $definition) {
            throw new LogicException(sprintf('Limit "%s" is not registered.', $key));
        }

        if (LimitKind::Bytes !== $definition->kind) {
            throw new LogicException(sprintf('Limit "%s" is not a byte limit.', $key));
        }

        try {
            return $this->limits->limitFor($this->context->get()->getId(), $key);
        } catch (Throwable $exception) {
            if (! $failOpen) {
                throw $exception;
            }

            report($exception);

            return null;
        }
    }
}
