<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;
use App\Domains\Onboarding\Enums\OnboardingOrigin;
use App\Domains\Onboarding\OnboardingBlock;
use App\Domains\Onboarding\Services\OnboardingProgress;
use App\Domains\Onboarding\Services\OnboardingRegistry;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Services\OnboardingWizard;
use App\Domains\Tenancy\Contracts\NewTenantInitializerInterface;
use Fapost\Foundation\Tenancy\Contracts\TenantOnboardingInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires the setup wizard: the registry (worker-lifetime, class names only), the scoped state and progress, Core's block
 * of steps, and the two contracts that reach the wizard from outside the domain, one from Tenancy (a tenant was
 * provisioned) and one from Foundation (an operator restarts it).
 *
 * The steps live here, not in the domain, because they know Assistant; the domain depends on no other domain.
 */
final class OnboardingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OnboardingRegistry::class);
        $this->app->scoped(OnboardingState::class);
        $this->app->scoped(OnboardingProgress::class, static fn (Application $app): OnboardingProgress => new OnboardingProgress(
            $app->make(OnboardingRegistry::class),
            static fn (string $class): OnboardingStepInterface => $app->make($class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->scoped(OnboardingWizard::class);

        // Plain bind: Core is the only implementation; an operator package calls the contract and never replaces it.
        $this->app->bind(TenantOnboardingInterface::class, CoreTenantOnboarding::class);
        $this->app->bind(NewTenantInitializerInterface::class, OnboardingInitializer::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(OnboardingRegistry::class);

        $registry->register(new OnboardingBlock(OnboardingOrigin::Core, array_map(
            static fn (CoreOnboardingStep $step): string => match ($step) {
                CoreOnboardingStep::Languages      => LanguagesStep::class,
                CoreOnboardingStep::FirstAssistant => FirstAssistantStep::class,
            },
            CoreOnboardingStep::cases(),
        )));

        // Tests register blocks of their own after boot, so the registry stays open in testing.
        $this->app->booted(function () use ($registry): void {
            if (! $this->app->environment('testing')) {
                $registry->freeze();
            }
        });
    }
}
