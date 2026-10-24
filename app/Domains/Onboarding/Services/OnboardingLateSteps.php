<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Throwable;

/**
 * The required steps that appeared after the tenant finished onboarding and are still open.
 *
 * "Late" means not in the tenant's recorded `known_steps`: a Core upgrade or an activated Solution added the step. A
 * step that is known and became open again from the data (the last assistant was deleted) is not late; it shows in the
 * checklist only. Only late candidates are checked, so a tenant with none costs a settings read and no step query.
 *
 * Bound `scoped`, like {@see OnboardingProgress}. A check that throws counts the step done and is logged: a banner
 * on a guess would be worse than none.
 */
final readonly class OnboardingLateSteps
{
    public function __construct(
        private OnboardingState $state,
        private OnboardingProgress $progress,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{key: string, url: string}> the open late steps, in the wizard's order
     */
    public function open(): array
    {
        try {
            if ($this->state->isPending()) {
                return [];
            }

            $known = $this->state->knownSteps();
        } catch (MissingSettings|QueryException $exception) {
            $this->logger->warning('The onboarding state could not be read; no late step is reported.', [
                'exception_class' => $exception::class,
            ]);

            return [];
        }

        $open = [];

        foreach ($this->progress->steps() as $step) {
            if (! $step->required() || in_array($step->key(), $known, true)) {
                continue;
            }

            try {
                if ($step->isComplete()) {
                    continue;
                }
            } catch (Throwable $exception) {
                $this->logger->warning('A late onboarding step could not be checked; it is counted complete.', [
                    'step'            => $step->key(),
                    'exception_class' => $exception::class,
                    'message'         => $exception->getMessage(),
                ]);

                continue;
            }

            $open[] = ['key' => $step->key(), 'url' => route($step->route(), [], false)];
        }

        return $open;
    }
}
