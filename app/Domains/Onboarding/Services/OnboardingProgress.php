<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\DTO\ProgressReport;
use App\Domains\Onboarding\DTO\StepProgress;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks the wizard's steps for the tenant of the current scope.
 *
 * Bound `scoped`: the steps are built here, through the resolver, from the classes the worker-lifetime registry holds,
 * so none of them outlives the request or job. Nothing is memoized; the wizard asks again after every write.
 *
 * A step whose check throws is logged, counted complete and flagged uncertain: the tenant is not locked out by a
 * broken check, and the wizard does not close on an answer it never got.
 */
final readonly class OnboardingProgress
{
    /**
     * @param  Closure(class-string<OnboardingStepInterface>): OnboardingStepInterface  $resolve
     */
    public function __construct(
        private OnboardingRegistry $registry,
        private Closure $resolve,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Every step of every block, in the wizard's order.
     *
     * @return list<OnboardingStepInterface>
     */
    public function steps(): array
    {
        $steps = [];

        foreach ($this->registry->blocks() as $block) {
            foreach ($block->steps as $class) {
                $steps[] = ($this->resolve)($class);
            }
        }

        return $steps;
    }

    public function report(): ProgressReport
    {
        $progress = [];

        foreach ($this->steps() as $step) {
            try {
                $progress[] = new StepProgress($step, $step->isComplete(), false);
            } catch (Throwable $exception) {
                $this->logger->error('An onboarding step could not be checked; it is counted complete.', [
                    'step'            => $step->key(),
                    'exception_class' => $exception::class,
                    'message'         => $exception->getMessage(),
                ]);

                $progress[] = new StepProgress($step, true, true);
            }
        }

        return new ProgressReport($progress);
    }

    /**
     * Route names the gate lets through while the wizard is open, on behalf of the steps.
     *
     * @return list<string>
     */
    public function allowedRoutes(): array
    {
        $routes = [];

        foreach ($this->steps() as $step) {
            array_push($routes, $step->route(), ...$step->routes());
        }

        return array_values(array_unique($routes));
    }
}
