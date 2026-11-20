<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * Holds a webhook request of a `sync` trigger open for its flow's answer, after the run was queued.
 *
 * Only with a slot ({@see WebhookWaitSlotsInterface}: `flow.webhook.sync_waiters_max` on the installation,
 * `flow.webhook.sync_waiters_per_tenant` per tenant) and for at most `flow.webhook.sync_wait_seconds`; the slot is given
 * back in `finally`. No slot, no answer in time, or a store that fails: null, and the sender gets 202.
 *
 * Bound scoped: it reads platform settings.
 */
final readonly class WebhookSyncWait
{
    public const string WAIT_SECONDS_SETTING = 'flow.webhook.sync_wait_seconds';

    public const string INSTALLATION_WAITERS_SETTING = 'flow.webhook.sync_waiters_max';

    public const string TENANT_WAITERS_SETTING = 'flow.webhook.sync_waiters_per_tenant';

    /** The longest a request may be held, whatever is stored: the setting's ceiling. */
    public const int MAX_WAIT_SECONDS = 30;

    /** A slot outlives its wait by this much before it lapses on its own. */
    private const int SLOT_GRACE_SECONDS = 5;

    public function __construct(
        private WebhookWaitSlotsInterface $slots,
        private WebhookReplyStoreInterface $replies,
        private PlatformSettingsReaderInterface $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $logContext  tenant, flow, trigger and delivery id, for the log lines
     */
    public function await(string $tenantId, string $triggerId, string $fireKey, array $logContext = []): ?WebhookReply
    {
        $seconds = min(self::MAX_WAIT_SECONDS, max(1, $this->settings->integer(self::WAIT_SECONDS_SETTING)));

        $slot = $this->slots->acquire(
            $tenantId,
            $this->settings->integer(self::INSTALLATION_WAITERS_SETTING),
            $this->settings->integer(self::TENANT_WAITERS_SETTING),
            $seconds + self::SLOT_GRACE_SECONDS,
        );

        if (null === $slot) {
            $this->logger->info('flow.webhook.sync_no_slot', $logContext);

            return null;
        }

        try {
            $reply = $this->replies->await($tenantId, $triggerId, $fireKey, $seconds);
        } finally {
            $this->slots->release($tenantId, $slot);
        }

        if (null === $reply) {
            $this->logger->info('flow.webhook.sync_timeout', $logContext);
        }

        return $reply;
    }
}
