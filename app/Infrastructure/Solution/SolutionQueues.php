<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\Services\InstalledSolutions;

/**
 * The queues of Solution jobs: one per installed Solution, `solution-jobs.<id>` (outside Core's `solutions.*` queues, whatever the id), all on the long connection and served by
 * one Horizon supervisor that balances between them, so a flood from one Solution neither holds the others back nor
 * touches Core's queues. The list depends on the image alone (the installed Solutions), never on a tenant; Horizon
 * reads it when it starts, so a newly installed Solution's queue is served after `horizon:terminate`, which every
 * upgrade runs anyway.
 */
final readonly class SolutionQueues
{
    public const string CONNECTION = 'redis-long';

    public const string SUPERVISOR = 'solution-jobs';

    public const string PREFIX = 'solution-jobs.';

    public function __construct(
        private InstalledSolutions $installed,
    ) {
    }

    public static function nameFor(string $solutionId): string
    {
        return self::PREFIX . $solutionId;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (InstalledSolution $solution): string => self::nameFor($solution->id), $this->installed->all());
    }

    /**
     * Fills the queues of the Solution jobs supervisor in Horizon's configuration, or removes the supervisor when no
     * Solution is installed: Horizon gives a supervisor without queues the `default` queue, which is not its work.
     */
    public function configureHorizon(): void
    {
        $names = $this->names();

        if ([] === $names) {
            config()->set('horizon.defaults.' . self::SUPERVISOR, null);

            foreach (array_keys((array) config('horizon.environments', [])) as $environment) {
                config()->set("horizon.environments.{$environment}." . self::SUPERVISOR, null);
            }

            $this->dropNullSupervisors();

            return;
        }

        config()->set('horizon.defaults.' . self::SUPERVISOR . '.queue', $names);

        // Horizon keeps at least one process per queue, so the ceiling grows with the queues: each Solution keeps one
        // worker and one more can join the busiest.
        $maxProcesses = count($names) + 1;
        config()->set('horizon.defaults.' . self::SUPERVISOR . '.maxProcesses', $maxProcesses);

        foreach (array_keys((array) config('horizon.environments', [])) as $environment) {
            if (null !== config("horizon.environments.{$environment}." . self::SUPERVISOR)) {
                config()->set("horizon.environments.{$environment}." . self::SUPERVISOR . '.maxProcesses', $maxProcesses);
            }
        }
    }

    private function dropNullSupervisors(): void
    {
        config()->set('horizon.defaults', array_filter((array) config('horizon.defaults', []), static fn (mixed $supervisor): bool => null !== $supervisor));

        foreach ((array) config('horizon.environments', []) as $environment => $supervisors) {
            config()->set("horizon.environments.{$environment}", array_filter((array) $supervisors, static fn (mixed $supervisor): bool => null !== $supervisor));
        }
    }
}
