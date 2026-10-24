<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\DTO\ChecklistView;
use App\Domains\Onboarding\DTO\StepProgress;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * The steps of the wizard as a checklist for the staff of a tenant that went through it, until every step is done or
 * the user hides it.
 *
 * Bound `scoped`, like {@see OnboardingProgress}. The domain knows no user: the caller passes what it asked of its own
 * user, so this stays free of the Staff domain. The checklist is for a tenant that was put into the wizard
 * (`started_at` is set); a tenant that existed before the wizard never gets it.
 */
final readonly class OnboardingChecklist
{
    public function __construct(
        private OnboardingState $state,
        private OnboardingProgress $progress,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  bool  $mayManageSettings  the user may do what the steps ask
     * @param  list<string>|null  $hiddenAt  the step keys that were registered when the user hid the checklist; `null` when never hidden
     */
    public function for(bool $mayManageSettings, ?array $hiddenAt): ?ChecklistView
    {
        if (! $mayManageSettings || ! $this->isOnboarded()) {
            return null;
        }

        $report = $this->progress->report();

        if (! $this->hasOpenStep($report->steps)) {
            return null;
        }

        $items = [];

        foreach ($report->steps as $progress) {
            $items[] = [
                'key'      => $progress->step->key(),
                'required' => $progress->step->required(),
                'complete' => $progress->complete,
                'url'      => route($progress->step->route(), [], false),
            ];
        }

        if ($this->isHidden($hiddenAt, array_column($items, 'key'))) {
            return null;
        }

        return new ChecklistView($items);
    }

    /**
     * The keys of every step in the registry now: what a user's hiding is remembered against.
     *
     * @return list<string>
     */
    public function registeredKeys(): array
    {
        return array_map(static fn (OnboardingStepInterface $step): string => $step->key(), $this->progress->steps());
    }

    /**
     * Whether the wizard is over and the tenant went through it. Unreadable settings count as no checklist.
     */
    private function isOnboarded(): bool
    {
        try {
            return ! $this->state->isPending() && $this->state->hasStarted();
        } catch (MissingSettings|QueryException $exception) {
            $this->logger->warning('The onboarding state could not be read; the checklist is not shown.', [
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * @param  list<StepProgress>  $steps
     */
    private function hasOpenStep(array $steps): bool
    {
        foreach ($steps as $progress) {
            if (! $progress->complete) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hidden while every step now in the registry was there when the user hid it.
     *
     * @param  list<string>|null  $hiddenAt
     * @param  list<string>  $current
     */
    private function isHidden(?array $hiddenAt, array $current): bool
    {
        return null !== $hiddenAt && [] === array_diff($current, $hiddenAt);
    }
}
