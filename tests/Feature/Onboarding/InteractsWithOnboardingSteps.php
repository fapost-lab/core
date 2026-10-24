<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\OnboardingOrigin;
use App\Domains\Onboarding\OnboardingBlock;
use App\Domains\Onboarding\Services\OnboardingRegistry;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Services\OnboardingWizard;
use App\Domains\Staff\Models\User;
use Closure;

/**
 * Helpers for the checklist and late-step tests: a tenant that went through the wizard and steps registered on top
 * of Core's.
 */
trait InteractsWithOnboardingSteps
{
    /**
     * A tenant put into the wizard and walked through Core's steps, so the wizard closed on its own.
     */
    protected function onboardTenant(): void
    {
        $state = $this->app->make(OnboardingState::class);
        $state->start();
        $state->confirm('languages');
        Assistant::factory()->create();
        $this->app->make(OnboardingWizard::class)->advance();
    }

    /**
     * Makes Core's optional steps (a channel, a second person) done by their data.
     */
    protected function completeCoreOptionalSteps(): void
    {
        Channel::withoutEvents(static fn (): Channel => Channel::factory()->create());
        User::factory()->create();
    }

    /**
     * Registers a step in a block of its own. Its answer is read from `$done` at every check; a closure that throws
     * makes the check throw.
     *
     * @param  Closure(): bool  $done
     */
    protected function registerStep(OnboardingOrigin $origin, string $key, bool $required, Closure $done): void
    {
        $step = new class ($key, $required, $done) implements OnboardingStepInterface {
            public function __construct(
                private readonly string $key,
                private readonly bool $required,
                private readonly Closure $done,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function required(): bool
            {
                return $this->required;
            }

            public function route(): string
            {
                return 'console.admin.usage.index';
            }

            public function routes(): array
            {
                return ['console.admin.usage.index'];
            }

            public function isComplete(): bool
            {
                return ($this->done)();
            }
        };

        $this->app->bind($step::class, static fn (): OnboardingStepInterface => $step);
        $this->app->make(OnboardingRegistry::class)->register(new OnboardingBlock($origin, [$step::class]));
    }
}
