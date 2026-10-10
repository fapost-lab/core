<?php

declare(strict_types=1);

namespace App\Domains\Staff\Jobs;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\LimitReachedNotification;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Events\LimitReached;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Support\TenantHost;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Contracts\LimitNoticeInterface;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitNotice;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Fapost\Foundation\Quota\Enums\LimitNoticeReason;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells a tenant's admins that a limit refused work: one database notification and one email each.
 *
 * Runs on the platform service queue (`messaging.system`) inside the tenant's switch. The message is
 * about the tenant's own state, not work its clients caused, so it keeps running in a stopped tenant.
 * Recipients are sent to with `notifyNow`, not as queued notifications: a queued notification would
 * restore the `User` outside the tenant's schema. The guard is set before delivery, so a retry never
 * double-delivers; one failing recipient does not stop the others.
 */
final class SendLimitNoticeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantId,
        public string $key,
        public string $kind,
        public int $limit,
        public ?int $used,
        public ?string $refused,
        public string $occurredAt,
        public ?string $periodEndsAt,
        public string $episodeKey,
    ) {
        $this->onQueue('messaging.system');
    }

    public static function for(LimitReached $event, string $episodeKey): self
    {
        return new self(
            $event->tenantId,
            $event->key,
            $event->kind->value,
            $event->limit,
            $event->used,
            $event->refused?->value,
            $event->occurredAt->format(DATE_ATOM),
            $event->periodEndsAt?->format(DATE_ATOM),
            $episodeKey,
        );
    }

    public function handle(
        TenantRepositoryInterface $tenantRepository,
        TenantSwitcher $tenantSwitcher,
        StaffRecipientResolver $resolver,
        LimitNoticeInterface $operatorNotice,
        LimitRegistryInterface $registry,
        LoggerInterface $logger,
    ): void {
        if (! Cache::add('limit_notice_job:' . $this->episodeKey, true, now()->addDay())) {
            return;
        }

        $tenant = $tenantRepository->getById($this->tenantId);
        $event  = $this->event();
        $label  = $registry->find($this->key)->label ?? $this->key;
        $panel  = TenantHost::urlFor($tenant, '/admin');

        $tenantSwitcher->runForTenant($tenant, function () use ($resolver, $operatorNotice, $logger, $event, $label, $panel): void {
            $recipients = $resolver->admins();

            if ($recipients->isEmpty()) {
                $logger->info('limit notice resolved no recipients', ['tenant_id' => $this->tenantId, 'key' => $this->key]);

                return;
            }

            // One question to the operator per language, not per person.
            $notices = [];

            foreach ($recipients as $recipient) {
                $locale = $recipient->preferredLocale();

                if (! array_key_exists($locale, $notices)) {
                    $notices[$locale] = $this->operatorNotice($operatorNotice, $locale);
                }

                $this->notifyRecipient($recipient, new LimitReachedNotification($event, $label, $notices[$locale], $panel), $logger);
            }
        });
    }

    private function operatorNotice(LimitNoticeInterface $operator, string $locale): ?LimitNotice
    {
        try {
            return $operator->noticeFor($this->tenantId, $this->key, $locale, null === $this->refused ? LimitNoticeReason::Reached : LimitNoticeReason::Refused);
        } catch (Throwable $exception) {
            // A faulty operator package never stops the notice: Core's own text is used.
            report($exception);

            return null;
        }
    }

    private function notifyRecipient(User $recipient, LimitReachedNotification $notification, LoggerInterface $logger): void
    {
        try {
            $recipient->notifyNow($notification);
        } catch (Throwable $exception) {
            $logger->warning('limit notice delivery failed', [
                'user_id'   => $recipient->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function event(): LimitReached
    {
        return new LimitReached(
            $this->tenantId,
            $this->key,
            LimitKind::from($this->kind),
            $this->limit,
            $this->used,
            null === $this->refused ? null : RefusedWork::from($this->refused),
            new DateTimeImmutable($this->occurredAt),
            null === $this->periodEndsAt ? null : new DateTimeImmutable($this->periodEndsAt),
        );
    }
}
