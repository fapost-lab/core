<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Admission\TriggerRateBreaker;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\Notifiers\InAppStaffNotifier;
use App\Domains\Staff\Notifications\StaffMessage;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the people who manage automations that the run rate breaker paused a trigger: one in-app notification each,
 * with a link to the flow, where the trigger is resumed.
 *
 * Queued after the commit by the one admission call that paused the trigger, so a pause is announced once. Runs on the
 * platform service queue (`messaging.system`) inside the tenant's switch, like a limit notice, and keeps running in a
 * stopped tenant: the message is about the tenant's own state. Sent with `notifyNow` (a queued notification would
 * restore the user outside the tenant), behind a guard set before delivery, so a retry never delivers twice; one failing
 * recipient does not stop the others.
 */
final class NotifyTriggerPausedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantId,
        public string $flowId,
        public string $triggerId,
        public ?string $triggerType,
        public string $pausedAt,
    ) {
        $this->onQueue('messaging.system');
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        StaffRecipientResolver $resolver,
        InAppStaffNotifier $notifier,
        LoggerInterface $logger,
    ): void {
        if (! Cache::add('flow_trigger_paused_notice:' . $this->tenantId . ':' . $this->triggerId . ':' . $this->pausedAt, true, now()->addDay())) {
            return;
        }

        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($resolver, $notifier, $logger): void {
            $recipients = $resolver->withPermission(Permission::ManageAutomations);

            if ($recipients->isEmpty()) {
                $logger->info('flow.trigger.paused_notice_no_recipients', ['tenant_id' => $this->tenantId, 'trigger_id' => $this->triggerId]);

                return;
            }

            $flowName = $this->flowName();
            $limit    = max(1, app(PlatformSettingsReaderInterface::class)->integer(TriggerRateBreaker::SETTING));

            foreach ($recipients as $recipient) {
                $this->notify($recipient, $notifier, $flowName, $limit, $logger);
            }
        });
    }

    private function notify(User $recipient, InAppStaffNotifier $notifier, string $flowName, int $limit, LoggerInterface $logger): void
    {
        $locale = $recipient->preferredLocale();

        try {
            $notifier->send($recipient, new StaffMessage(
                text: __('builder.trigger_state.paused_notice', [
                    'type'  => __('builder.trigger_state.types.' . ($this->triggerType ?? 'other'), [], $locale),
                    'flow'  => $flowName,
                    'limit' => $limit,
                ], $locale),
                url: '/builder/flows/' . $this->flowId,
                actionLabel: __('builder.trigger_state.open_flow', [], $locale),
            ));
        } catch (Throwable $exception) {
            $logger->warning('flow.trigger.paused_notice_failed', [
                'user_id'   => $recipient->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function flowName(): string
    {
        try {
            return (string) app(FlowDraftRepositoryInterface::class)->findByFlowId($this->flowId)->name;
        } catch (ModelNotFoundException) {
            return $this->flowId;
        }
    }
}
