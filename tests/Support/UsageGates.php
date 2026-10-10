<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Messaging\OutboundVolumeGate;
use App\Domains\Tenancy\Services\LimitRegistry;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\UnlimitedUsageMeter;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;

/**
 * Builds the per-period quota wrappers by hand for tests that construct a service without the
 * container: both volume keys registered, a tenant in the context, and the given meter behind it.
 */
final class UsageGates
{
    public const string TENANT_ID = '01k00000000000000000000000';

    public static function quota(?UsageMeterInterface $meter = null): PeriodQuota
    {
        $registry = new LimitRegistry();
        $registry->register(new LimitDefinition(OutboundVolumeGate::LIMIT_KEY, 'Outbound messages', 'messages', LimitKind::PerPeriod));
        $registry->register(new LimitDefinition(CallNodeHandler::LIMIT_KEY, 'Call executions', 'calls', LimitKind::PerPeriod));

        $context = new TenantContext();
        $context->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));

        return new PeriodQuota($registry, $meter ?? new UnlimitedUsageMeter(), $context);
    }

    public static function gate(?UsageMeterInterface $meter = null): OutboundVolumeGate
    {
        return new OutboundVolumeGate(self::quota($meter));
    }
}
