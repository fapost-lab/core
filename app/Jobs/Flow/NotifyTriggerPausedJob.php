<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Admission\TriggerRateBreaker;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\Notifiers\InAppStaffNotifier;
use App\Domains\Staff\Notifications\StaffMessage;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the people who can resume a trigger the run rate breaker paused that it was paused: one in-app notification
 * each, with a path to the flow in the builder, where the trigger is resumed. A recipient holds the right to manage
 * automations and passes the flow's `resumeTrigger` policy (editing it, with access to its assistant), so nobody is told
 * about a flow they cannot open; a flow deleted since is announced to nobody.
 *
 * Queued after the commit by the one admission call that paused the trigger, so a pause is announced once. Runs on the
 * platform service queue (`messaging.system`) inside the tenant's switch, like a limit notice, and keeps running in a
 * stopped tenant: the message is about the tenant's own state. Sent with `notifyNow` (a queued notification would
 * restore the user outside the tenant). A guard set after the recipients and the flow were read, right before delivery,
 * keeps a retry from delivering twice, while a job that failed before it is retried in full; one failing recipient does
 * not stop the others.
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
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($resolver, $notifier, $logger): void {
            $draft = $this->draft();

            if (null === $draft) {
                $logger->info('flow.trigger.paused_notice_no_flow', ['tenant_id' => $this->tenantId, 'flow_id' => $this->flowId, 'trigger_id' => $this->triggerId]);

                return;
            }

            $recipients = $resolver->withPermission(Permission::ManageAutomations)
                ->filter(static fn (User $user): bool => Gate::forUser($user)->allows('resumeTrigger', $draft));

            if ($recipients->isEmpty()) {
                $logger->info('flow.trigger.paused_notice_no_recipients', ['tenant_id' => $this->tenantId, 'trigger_id' => $this->triggerId]);

                return;
            }

            $limit = max(1, app(PlatformSettingsReaderInterface::class)->integer(TriggerRateBreaker::SETTING));
            $path  = app(UrlGenerator::class)->route('builder.flows.show', ['flow' => $this->flowId], false);

            // Set once everything the notice needs was read, right before delivery: a job that failed before it is
            // retried in full, and a retry after it delivers nothing twice.
            if (! Cache::add('flow_trigger_paused_notice:' . $this->tenantId . ':' . $this->triggerId . ':' . $this->pausedAt, true, now()->addDay())) {
                return;
            }

            foreach ($recipients as $recipient) {
                $this->notify($recipient, $notifier, (string) $draft->name, $limit, $path, $logger);
            }
        });
    }

    private function notify(User $recipient, InAppStaffNotifier $notifier, string $flowName, int $limit, string $path, LoggerInterface $logger): void
    {
        $locale = $recipient->preferredLocale();

        try {
            $notifier->send($recipient, new StaffMessage(
                text: __('builder.trigger_state.paused_notice', [
                    'type'  => $this->typeLabel($locale),
                    'flow'  => $flowName,
                    'limit' => $limit,
                ], $locale),
                url: $path,
                actionLabel: __('builder.trigger_state.open_flow', [], $locale),
            ));
        } catch (Throwable $exception) {
            $logger->warning('flow.trigger.paused_notice_failed', [
                'user_id'   => $recipient->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function draft(): ?FlowDraft
    {
        try {
            return app(FlowDraftRepositoryInterface::class)->findByFlowId($this->flowId);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /**
     * A built-in type's word in the recipient's language; a registered type's own name; "automatic" without one.
     */
    private function typeLabel(string $locale): string
    {
        $key = 'builder.trigger_state.types.' . ($this->triggerType ?? 'other');

        return trans()->has($key, $locale) ? (string) __($key, [], $locale) : (string) $this->triggerType;
    }
}
