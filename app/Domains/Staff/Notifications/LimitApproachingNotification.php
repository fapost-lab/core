<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\LimitNoticeFormat;
use App\Domains\Tenancy\Events\LimitApproaching;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Quota\DTO\LimitNotice;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Warns one admin that a per-period limit of the tenant is close to being used up, in the admin's
 * language. Nothing was refused yet.
 *
 * Sent with `notifyNow` from {@see \App\Domains\Staff\Jobs\SendLimitNoticeJob}, never queued, for the
 * reason {@see LimitReachedNotification} is not. Core writes the name of the limit, the numbers and the
 * end of the period; "what to do next" is the operator's {@see LimitNotice} when it gave one, else Core's
 * "contact the platform administrator". The stored `title` and `body` use the same keys as
 * {@see LimitReachedNotification}.
 */
final class LimitApproachingNotification extends Notification
{
    /**
     * @param  string  $fallbackLabel  English name of the limit, for a key that has no translation
     * @param  string  $panelUrl  the tenant's usage page on its own host, where the button leads when the operator gave none
     */
    public function __construct(
        private readonly LimitApproaching $event,
        private readonly string $fallbackLabel,
        private readonly ?LimitNotice $notice,
        private readonly string $panelUrl,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        $action = LimitNoticeFormat::action($this->notice, $this->panelUrl);

        return [
            'title'      => $this->title(),
            'body'       => implode(' ', $this->lines()),
            'action'     => ['label' => $action['label'], 'url' => $action['url']],
            'kind'       => 'limit_approaching',
            'key'        => $this->event->key,
            'limit'      => $this->event->limit,
            'used'       => $this->event->used,
            'occurredAt' => $this->event->occurredAt->format(DATE_ATOM),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $action = LimitNoticeFormat::action($this->notice, $this->panelUrl);

        return (new MailMessage())
            ->subject($this->title())
            ->markdown('mail.staff.limit', [
                'recipientName' => $notifiable->name,
                'title'         => $this->title(),
                'lines'         => $this->lines(),
                'actionLabel'   => $action['label'],
                'actionUrl'     => $action['url'],
            ]);
    }

    private function title(): string
    {
        return __('limits.approaching.title', ['limit' => LimitNoticeFormat::label($this->event->key, $this->fallbackLabel)]);
    }

    /**
     * The paragraphs of the message: the numbers, when the period ends, what happens next, what to do.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        $lines = [$this->usageLine()];

        if (null !== $this->event->periodEndsAt) {
            $lines[] = __('limits.approaching.period_ends', [
                'date' => CarbonImmutable::instance($this->event->periodEndsAt)
                    ->utc()
                    ->locale(app()->getLocale())
                    ->isoFormat('LL'),
            ]);
        }

        $lines[] = __('limits.approaching.consequence');
        $lines[] = $this->notice?->message ?? __('limits.who_to_ask');

        return $lines;
    }

    private function usageLine(): string
    {
        return __('limits.approaching.usage', [
            'used'    => LimitNoticeFormat::amount($this->event->used, LimitKind::PerPeriod),
            'limit'   => LimitNoticeFormat::amount($this->event->limit, LimitKind::PerPeriod),
            'percent' => intdiv($this->event->used * 100, $this->event->limit),
        ]);
    }
}
