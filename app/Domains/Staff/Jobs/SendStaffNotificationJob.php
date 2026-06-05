<?php

declare(strict_types=1);

namespace App\Domains\Staff\Jobs;

use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers a staff escalation notification raised by a `notify_staff` node.
 *
 * Runs on the platform service queue (`messaging.system`). Re-establishes tenant
 * context (queue workers have no HTTP tenant middleware), resolves the recipient
 * set from the node's target config, and fans the already-rendered message out
 * across the selected channels for each recipient. A Redis-backed idempotency
 * guard keyed on (session, node) ensures a retried job never double-delivers.
 */
final class SendStaffNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $targetConfig  Recipient selection block (target/role/user_ids).
     * @param  list<string>          $channels      Selected delivery channel values.
     */
    public function __construct(
        public string $tenantId,
        public string $sessionId,
        public string $nodeId,
        public array $targetConfig,
        public array $channels,
        public string $message,
    ) {
        $this->onQueue('messaging.system');
    }

    public function handle(
        TenantRepositoryInterface $tenantRepository,
        TenantSwitcher $tenantSwitcher,
        StaffNotifierRegistry $notifiers,
        StaffRecipientResolver $resolver,
        LoggerInterface $logger,
    ): void {
        // Retry-safety: only the first attempt for this (session, node) delivers.
        $guardKey = "staff_notify:{$this->tenantId}:{$this->sessionId}:{$this->nodeId}";

        if (! Cache::add($guardKey, true, now()->addDay())) {
            return;
        }

        $tenant = $tenantRepository->getById($this->tenantId);

        $tenantSwitcher->runForTenant($tenant, function () use ($notifiers, $resolver, $logger): void {
            $assistantId = FlowSession::query()
                ->whereKey($this->sessionId)
                ->value('assistant_id');

            $recipients = $resolver->resolve($this->targetConfig, $assistantId);

            if ($recipients->isEmpty()) {
                $logger->info('notify_staff resolved no recipients', [
                    'session' => $this->sessionId,
                    'node'    => $this->nodeId,
                ]);

                return;
            }

            foreach ($recipients as $recipient) {
                foreach ($this->channels as $channel) {
                    $notifier = $notifiers->get($channel);

                    if (null === $notifier || ! $notifier->isAvailableFor($recipient)) {
                        continue;
                    }

                    try {
                        $notifier->send($recipient, $this->message);
                    } catch (Throwable $e) {
                        // One failing channel must not block the others or fail the
                        // whole job (which would re-deliver on retry).
                        $logger->warning('notify_staff delivery failed', [
                            'channel'   => $channel,
                            'user_id'   => $recipient->getKey(),
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });
    }
}
