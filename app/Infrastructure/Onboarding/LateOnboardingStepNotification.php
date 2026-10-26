<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Staff\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Tells one administrator that a required setup step appeared after the tenant finished onboarding, in the
 * administrator's own language, with a link to the step.
 *
 * Sent with `notifyNow` from {@see LateStepAnnouncer}, never queued. Stored in the shape the bell reads: `title`,
 * `body` and Core's `action` key.
 */
final class LateOnboardingStepNotification extends Notification
{
    public function __construct(
        private readonly string $stepKey,
        private readonly string $url,
    ) {
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
        $locale = $notifiable->preferredLocale();
        $titles = trans('console.onboarding.steps.' . $this->stepKey . '.title', [], $locale);
        $step   = is_string($titles) && 'console.onboarding.steps.' . $this->stepKey . '.title' !== $titles ? $titles : $this->stepKey;

        return [
            'title'  => trans('console.onboarding.late.notification_title', [], $locale),
            'body'   => trans('console.onboarding.late.notification_body', ['step' => $step], $locale),
            'action' => [
                'label' => trans('console.onboarding.late.action', [], $locale),
                'url'   => $this->url,
            ],
            'kind' => 'onboarding_late_step',
            'step' => $this->stepKey,
        ];
    }
}
