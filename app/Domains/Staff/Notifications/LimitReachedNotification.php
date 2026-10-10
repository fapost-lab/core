<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Events\LimitReached;
use Fapost\Foundation\Quota\DTO\LimitNotice;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells one admin that a limit of the tenant refused work, in the admin's language.
 *
 * Sent with `notifyNow` from {@see \App\Domains\Staff\Jobs\SendLimitNoticeJob}, never queued: the
 * notifiable is restored inside the tenant's switch there. Core writes the name of the limit, the
 * numbers and what was refused; "what to do next" is the operator's {@see LimitNotice} when it gave one,
 * else Core's "contact the platform administrator".
 *
 * The stored `title` and `body` use the keys Filament's database notifications use, so one list can
 * show both kinds.
 */
final class LimitReachedNotification extends Notification
{
    /**
     * @param  string  $fallbackLabel  English name of the limit, for a key that has no translation
     * @param  string  $panelUrl  where the admin opens the panel, on the tenant's own host
     */
    public function __construct(
        private readonly LimitReached $event,
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
        $action = $this->action();

        return [
            'title'      => $this->title(),
            'body'       => implode(' ', $this->lines()),
            'action'     => ['label' => $action['label'], 'url' => $action['url']],
            'kind'       => 'limit_reached',
            'key'        => $this->event->key,
            'limit'      => $this->event->limit,
            'used'       => $this->event->used,
            'occurredAt' => $this->event->occurredAt->format(DATE_ATOM),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $action = $this->action();

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
        return __('limits.title', ['limit' => $this->label()]);
    }

    /**
     * The paragraphs of the message: the numbers, what was refused, what happens next, what to do.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        return [
            $this->usageLine(),
            null === $this->event->refused
                ? __('limits.filled')
                : __('limits.refused.' . $this->event->refused->value),
            __('limits.consequence.' . $this->event->kind->value),
            $this->notice?->message ?? __('limits.who_to_ask'),
        ];
    }

    private function usageLine(): string
    {
        $limit = $this->amount($this->event->limit);

        if (null === $this->event->used) {
            return __('limits.usage_unknown', ['limit' => $limit]);
        }

        return __(
            LimitKind::PerPeriod === $this->event->kind ? 'limits.usage_period' : 'limits.usage',
            ['used' => $this->amount($this->event->used), 'limit' => $limit],
        );
    }

    /**
     * @return array{label: string, url: string}
     */
    private function action(): array
    {
        if (null !== $this->notice && null !== $this->notice->actionLabel && null !== $this->notice->actionUrl) {
            return ['label' => $this->notice->actionLabel, 'url' => $this->notice->actionUrl];
        }

        return ['label' => __('limits.open_panel'), 'url' => $this->panelUrl];
    }

    private function label(): string
    {
        $translation = 'limits.keys.' . $this->event->key;
        $label       = __($translation);

        return $label === $translation ? $this->fallbackLabel : $label;
    }

    private function amount(int $value): string
    {
        if (LimitKind::Bytes !== $this->event->kind) {
            return number_format($value, 0, '.', ' ');
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size  = (float) $value;
        $unit  = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            ++$unit;
        }

        return mb_rtrim(mb_rtrim(number_format($size, 1, '.', ' '), '0'), '.') . ' ' . $units[$unit];
    }
}
