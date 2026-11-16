<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use BackedEnum;
use Fapost\Foundation\Solution\Queue\SolutionJob;
use Fapost\Foundation\Solution\Queue\SolutionJobRejectedException;
use Fapost\Foundation\Solution\Queue\SolutionQueueInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use ReflectionClass;
use ReflectionProperty;

/**
 * The one way a Solution queues work. Stamps the current tenant on the job, routes it to the Solution's own queue and
 * refuses what would run wrong later, when nobody is there to see it:
 *
 * - a job outside a tenant (it would run in no tenant's schema);
 * - a job of a Solution not installed, or whose class does not live in that Solution's package (a Solution cannot queue
 *   work in another's name);
 * - a property that is not a scalar, null, a backed enum or an array of those: a model would be restored before the
 *   worker switches into the tenant, from another schema, and an object may change shape across an upgrade;
 * - a timeout below one second or above {@see SolutionQueueInterface::MAX_TIMEOUT_SECONDS}, which the long connection's
 *   `retry_after` must exceed;
 * - Laravel's queue attributes, a `queue()` method, a `middleware` property or `ShouldBeUnique` ({@see self::checkShape()}).
 *
 * The container is the only thing held: the tenant context is scoped.
 */
final readonly class CoreSolutionQueue implements SolutionQueueInterface
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function dispatch(SolutionJob $job, int $delaySeconds = 0): void
    {
        $context = $this->container->make(TenantContextInterface::class);

        if (!$context->isResolved()) {
            throw SolutionJobRejectedException::noTenant($job::class);
        }

        $solutionId = $job->solution();
        $solution   = $this->container->make(InstalledSolutions::class)->find($solutionId);

        if (null === $solution) {
            throw SolutionJobRejectedException::notInstalled($job::class, $solutionId);
        }

        if (!$this->livesIn($job, $solution->composerPath)) {
            throw SolutionJobRejectedException::notOwned($job::class, $solutionId);
        }

        // Zero or less turns the worker's alarm off; either way the job could outlive the connection's `retry_after`.
        if ($job->timeout < 1 || $job->timeout > self::MAX_TIMEOUT_SECONDS) {
            throw SolutionJobRejectedException::timeoutTooLong($job::class, $job->timeout, self::MAX_TIMEOUT_SECONDS);
        }

        $this->checkShape($job);
        $this->checkProperties($job);

        $job->forTenant($context->get()->getId());
        $job->connection = SolutionQueues::CONNECTION;
        $job->queue      = SolutionQueues::nameFor($solutionId);
        $job->delay      = $delaySeconds > 0 ? $delaySeconds : null;

        $this->container->make(Dispatcher::class)->dispatch($job);
    }

    private function livesIn(SolutionJob $job, ?string $composerPath): bool
    {
        $file = (new ReflectionClass($job))->getFileName();
        $root = null === $composerPath ? false : realpath(dirname($composerPath));
        $real = false === $file ? false : realpath($file);

        return false !== $root && false !== $real && str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * Refuses what would let a job choose its own queue, timing or locking behind the checks above: Laravel's queue
     * attributes (`#[Timeout]`, `#[Queue]`, `#[Connection]`, `#[Delay]`… win over what Core stamps or checks), a `queue()`
     * method (the dispatcher hands the push to it), a `middleware` property (run after Core's gate), and uniqueness, whose
     * lock Laravel takes only for a `dispatch()` helper call, not for this dispatcher.
     */
    private function checkShape(SolutionJob $job): void
    {
        for ($class = new ReflectionClass($job); false !== $class && SolutionJob::class !== $class->getName(); $class = $class->getParentClass()) {
            foreach ($class->getAttributes() as $attribute) {
                if (str_starts_with($attribute->getName(), 'Illuminate\\Queue\\Attributes\\')) {
                    throw new SolutionJobRejectedException(sprintf('Solution job %s: the queue attribute %s is not allowed; Core sets the queue, connection and delay, and the timeout is the $timeout property.', $job::class, $attribute->getName()));
                }
            }
        }

        if (method_exists($job, 'queue')) {
            throw new SolutionJobRejectedException(sprintf('Solution job %s: a queue() method is not allowed; Core puts the job on its Solution\'s queue.', $job::class));
        }

        if ([] !== (property_exists($job, 'middleware') ? (array) $job->middleware : [])) {
            throw new SolutionJobRejectedException(sprintf('Solution job %s: a middleware property is not allowed; Core\'s gate is the only middleware.', $job::class));
        }

        if ($job instanceof ShouldBeUnique) {
            throw new SolutionJobRejectedException(sprintf('Solution job %s: ShouldBeUnique is not supported for Solution jobs yet.', $job::class));
        }
    }

    /**
     * Every property the job serializes, private ones of an intermediate parent class included; SolutionJob's own are
     * Core's and skipped.
     */
    private function checkProperties(SolutionJob $job): void
    {
        $own = array_map(static fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass(SolutionJob::class))->getProperties());

        foreach (get_mangled_object_vars($job) as $key => $value) {
            $name = str_contains($key, "\0") ? mb_substr($key, (int) mb_strrpos($key, "\0") + 1) : $key;

            if ($key === $name && in_array($name, $own, true)) {
                continue;
            }

            if (!$this->isPlain($value)) {
                throw SolutionJobRejectedException::unsupportedProperty($job::class, $name, get_debug_type($value));
            }
        }
    }

    private function isPlain(mixed $value): bool
    {
        if (null === $value || is_scalar($value) || $value instanceof BackedEnum) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$this->isPlain($item)) {
                return false;
            }
        }

        return true;
    }
}
