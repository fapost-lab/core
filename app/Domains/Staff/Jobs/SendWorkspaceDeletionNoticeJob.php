<?php

declare(strict_types=1);

namespace App\Domains\Staff\Jobs;

use App\Domains\Staff\Mail\WorkspaceDeletionMail;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Events\WorkspaceDeletionCancelled;
use App\Domains\Tenancy\Events\WorkspaceDeletionScheduled;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Support\TenantHost;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails every admin of a tenant (the platform support user never) that the workspace deletion was scheduled or
 * cancelled, in the admin's own language. Runs on the platform service queue inside the tenant's switch. The message
 * is about the tenant's own state, so it keeps running in a stopped tenant. One failing recipient does not stop the
 * others.
 */
final class SendWorkspaceDeletionNoticeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantId,
        public string $kind,
        public string $requestedByName,
        public string $requestedByEmail,
        public string $requestedAt,
        public string $startsAt,
        public ?string $cancelledByName,
        public ?string $cancelledByEmail,
        public ?string $cancelledAt,
    ) {
        $this->onQueue('messaging.system');
    }

    public static function for(WorkspaceDeletionScheduled|WorkspaceDeletionCancelled $event): self
    {
        $cancelled = $event instanceof WorkspaceDeletionCancelled;

        return new self(
            $event->tenantId,
            $cancelled ? WorkspaceDeletionMail::CANCELLED : WorkspaceDeletionMail::SCHEDULED,
            $event->requestedByName,
            $event->requestedByEmail,
            $event->requestedAt->format(DATE_ATOM),
            $event->startsAt->format(DATE_ATOM),
            $cancelled ? $event->cancelledByName : null,
            $cancelled ? $event->cancelledByEmail : null,
            $cancelled ? $event->cancelledAt->format(DATE_ATOM) : null,
        );
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        StaffRecipientResolver $resolver,
        Mailer $mailer,
        LoggerInterface $logger,
    ): void {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $settingsUrl = TenantHost::urlFor($tenant, '/admin/tenant-settings');

        $switcher->runForTenant($tenant, function () use ($resolver, $mailer, $logger, $tenant, $settingsUrl): void {
            foreach ($resolver->admins() as $admin) {
                try {
                    $mailer->to($admin->email)->locale($admin->preferredLocale())->send(new WorkspaceDeletionMail(
                        kind: $this->kind,
                        recipientName: $admin->name,
                        workspace: $tenant->getSlug(),
                        requestedByName: $this->requestedByName,
                        requestedByEmail: $this->requestedByEmail,
                        requestedAt: $this->requestedAt,
                        startsAt: $this->startsAt,
                        cancelledByName: $this->cancelledByName,
                        cancelledByEmail: $this->cancelledByEmail,
                        settingsUrl: $settingsUrl,
                    ));
                } catch (Throwable $exception) {
                    $logger->warning('workspace deletion notice not delivered', [
                        'user_id'   => $admin->getKey(),
                        'exception' => $exception::class,
                    ]);
                }
            }
        });
    }
}
