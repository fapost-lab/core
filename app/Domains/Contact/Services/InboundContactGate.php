<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Tenancy\Services\PeriodQuota;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;

/**
 * Asks the operator whether an inbound message's sender may be active in the current period, before
 * the platform stores anything about them.
 *
 * The unit is the sender's identity (tenant, platform, external id), not a contact row: the contact
 * may not exist yet, and the key has to be the same before and after it is created. The operator
 * counts a contact once per period and answers "allowed" for one already counted, so Core asks on
 * every inbound message and caches nothing. A refusal is kept for the tenant's administrators
 * ({@see LimitRefusalRecorder}) and logged without the sender's identity.
 *
 * Runs inside a tenant switch.
 */
final readonly class InboundContactGate
{
    public const string LIMIT_KEY = 'monthly_active_contacts';

    public function __construct(
        private PeriodQuota $quota,
        private LimitRefusalRecorder $refusals,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  CarbonImmutable  $occurredAt  when the message was received; the same on every retry of the delivery
     *
     * @return bool true when the message may be processed, false when it was refused and recorded
     */
    public function admit(
        string $tenantId,
        PlatformEnum $platform,
        string $externalUserId,
        string $channelId,
        CarbonImmutable $occurredAt,
    ): bool {
        $subjectHash = hash('sha256', $tenantId . '|' . $platform->value . '|' . $externalUserId);

        $decision = $this->quota->consume(self::LIMIT_KEY, 'contact:' . $subjectHash, $occurredAt);

        if ($decision->allowed) {
            return true;
        }

        $this->refusals->record(self::LIMIT_KEY, $subjectHash, $channelId, CarbonImmutable::now());

        $this->logger->info('quota.inbound_refused', [
            'tenant_id'  => $tenantId,
            'channel_id' => $channelId,
            'limit_key'  => self::LIMIT_KEY,
            'limit'      => $decision->limit,
            'used'       => $decision->used,
        ]);

        return false;
    }
}
