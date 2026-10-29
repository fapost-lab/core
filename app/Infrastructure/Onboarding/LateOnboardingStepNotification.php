<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Staff\Contracts\LocalizedDatabaseNotification;
use App\Domains\Staff\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Tells one administrator that a required setup step appeared after the tenant finished onboarding, with a link to the
 * step.
 *
 * Sent with `notifyNow` from {@see LateStepAnnouncer}, never queued. Stored in the shape the bell reads: `title`,
 * `body` and Core's `action` key, in the administrator's own language; the bell renders it again in the language of the
 * page from the stored `step` ({@see LocalizedDatabaseNotification}).
 */
final class LateOnboardingStepNotification extends Notification implements LocalizedDatabaseNotification
{
    public function __construct(
        private readonly string $stepKey,
        private readonly string $url,
    ) {
    }

    public static function localize(array $data, string $locale): array
    {
        $step = $data['step'] ?? null;

        return is_string($step) && '' !== $step ? self::text($step, $locale) : [];
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, action: array{label: string, url: string}, kind: string, step: string}
     */
    public function toArray(User $notifiable): array
    {
        $text = self::text($this->stepKey, $notifiable->preferredLocale());

        return [
            'title'  => $text['title'],
            'body'   => $text['body'],
            'action' => [
                'label' => $text['actionLabel'],
                'url'   => $this->url,
            ],
            'kind' => 'onboarding_late_step',
            'step' => $this->stepKey,
        ];
    }

    /**
     * @return array{title: string, body: string, actionLabel: string}
     */
    private static function text(string $stepKey, string $locale): array
    {
        $key   = 'console.onboarding.steps.' . $stepKey . '.title';
        $title = trans($key, [], $locale);
        $step  = is_string($title) && $key !== $title ? $title : $stepKey;

        return [
            'title'       => (string) trans('console.onboarding.late.notification_title', [], $locale),
            'body'        => (string) trans('console.onboarding.late.notification_body', ['step' => $step], $locale),
            'actionLabel' => (string) trans('console.onboarding.late.action', [], $locale),
        ];
    }
}
