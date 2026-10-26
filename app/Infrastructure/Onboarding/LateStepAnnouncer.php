<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Onboarding\Services\OnboardingAnnouncements;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts each late required step into the bell of the people who can finish it, once.
 *
 * Called on a full page load of the tenant's staff, from the shared props: whoever loads a page first after the step
 * appeared announces it, under a short lock per tenant so concurrent first loads do not notify twice. The announced keys
 * are kept in their own settings group ({@see OnboardingAnnouncements}), never in the wizard's state, so an administrator who arrives later finds the banner but not a new notification.
 *
 * It never throws into the page: a failure is logged and the next load tries again.
 */
final readonly class LateStepAnnouncer
{
    private const int LOCK_SECONDS = 10;

    public function __construct(
        private OnboardingAnnouncements $announcements,
        private TenantContextInterface $tenants,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  list<array{key: string, url: string}>  $openSteps  the open late steps
     */
    public function announce(array $openSteps): void
    {
        try {
            if ([] === $openSteps || [] === $this->unannounced($openSteps)) {
                return;
            }

            $lock = Cache::lock('onboarding:announce:' . $this->tenants->get()->getId(), self::LOCK_SECONDS);

            // Someone else is announcing right now; the next page load sees the result.
            $lock->get(function () use ($openSteps): void {
                $fresh = $this->unannounced($openSteps);

                if ([] === $fresh) {
                    return;
                }

                $recipients = $this->recipients();

                foreach ($fresh as $step) {
                    foreach ($recipients as $user) {
                        $user->notifyNow(new LateOnboardingStepNotification($step['key'], $step['url']));
                    }
                }

                $this->announcements->announce(array_column($fresh, 'key'));
            });
        } catch (Throwable $exception) {
            $this->logger->warning('A late onboarding step could not be announced.', [
                'exception_class' => $exception::class,
                'message'         => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<array{key: string, url: string}>  $openSteps
     * @return list<array{key: string, url: string}>
     */
    private function unannounced(array $openSteps): array
    {
        $announced = $this->announcements->announced();

        return array_values(array_filter($openSteps, static fn (array $step): bool => ! in_array($step['key'], $announced, true)));
    }

    /**
     * Active staff who may manage settings; administrators hold every permission through `Gate::before`.
     *
     * @return list<User>
     */
    private function recipients(): array
    {
        return User::query()
            ->withoutPlatformSupport()
            ->where('is_active', true)
            ->where('status', UserStatus::Active->value)
            ->get()
            ->filter(static fn (User $user): bool => $user->can(Permission::ManageSettings->value))
            ->values()
            ->all();
    }
}
