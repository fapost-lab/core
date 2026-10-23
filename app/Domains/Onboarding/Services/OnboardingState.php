<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use App\Domains\Onboarding\Enums\OnboardingStatus;
use App\Domains\Onboarding\Settings\OnboardingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Reads and writes the current tenant's wizard state.
 *
 * Every call builds a fresh {@see OnboardingSettings}, which loads lazily from the connection that is current, so the
 * answer is always the tenant's of the moment: a switch into another tenant (the operator restarting a wizard, a
 * provisioning run) cannot see an instance loaded for the outer one. The cost is one small read per call.
 *
 * Only the wizard writes the state; the gate only reads it ({@see self::isPending()}).
 */
final readonly class OnboardingState
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Whether the tenant is held in the wizard. A tenant whose state cannot be read (the settings migration has not
     * reached it yet) is not held: the wizard is a convenience, and it must not close a working console.
     */
    public function isPending(): bool
    {
        try {
            return OnboardingStatus::Pending === $this->settings()->state;
        } catch (MissingSettings|QueryException $exception) {
            $this->logger->warning('The onboarding state could not be read; the tenant is treated as onboarded.', [
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function confirmedSteps(): array
    {
        return array_values($this->settings()->confirmed_steps);
    }

    public function isConfirmed(string $key): bool
    {
        return in_array($key, $this->confirmedSteps(), true);
    }

    public function confirm(string $key): void
    {
        $settings = $this->settings();

        if (in_array($key, $settings->confirmed_steps, true)) {
            return;
        }

        $settings->confirmed_steps = [...array_values($settings->confirmed_steps), $key];
        $settings->save();
    }

    /**
     * Holds the tenant in the wizard from now: confirmations are forgotten, steps derived from data stay as they are.
     * Starting a pending wizard again starts it over.
     */
    public function start(): void
    {
        $settings                  = $this->settings();
        $settings->state           = OnboardingStatus::Pending;
        $settings->confirmed_steps = [];
        $settings->started_at      = CarbonImmutable::now()->toIso8601String();
        $settings->completed_at    = null;
        $settings->save();
    }

    /**
     * Closes the wizard. Called by the wizard when its required steps are done, and by whatever creates a tenant that
     * is already populated.
     */
    public function complete(): void
    {
        $settings = $this->settings();

        if (OnboardingStatus::Complete === $settings->state) {
            return;
        }

        $settings->state        = OnboardingStatus::Complete;
        $settings->completed_at = CarbonImmutable::now()->toIso8601String();
        $settings->save();
    }

    private function settings(): OnboardingSettings
    {
        return new OnboardingSettings();
    }
}
