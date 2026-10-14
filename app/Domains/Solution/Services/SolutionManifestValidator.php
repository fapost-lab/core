<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\InstalledPackage;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionDiscovery;
use App\Domains\Solution\DTO\SolutionViolation;
use Fapost\Foundation\Lifecycle\AbstractSolutionServiceProvider;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Fapost\Foundation\Solution\Manifest\ManifestSchema;
use ReflectionClass;
use Throwable;

/**
 * Applies the Foundation manifest rules to every candidate package, then the checks that need the
 * whole installation: the provider class exists and is a Solution provider, and no two packages
 * claim the same id. Fails closed: a package with a violation, and both packages of a collision,
 * never reach `valid`.
 */
final class SolutionManifestValidator
{
    public function __construct(private readonly ManifestParser $parser)
    {
    }

    /**
     * @param  list<InstalledPackage>  $packages
     */
    public function validate(array $packages, string $installedJsonPath): SolutionDiscovery
    {
        $violations = [];
        $warnings   = [];
        $byId       = [];
        $watched    = [];

        foreach ($packages as $package) {
            if (null !== $package->composerPath) {
                $watched[] = $package->composerPath;
            }

            if (ManifestSchema::PACKAGE_TYPE !== $package->type) {
                $warnings[] = sprintf(
                    '%s: carries extra.fapost but its type is "%s", not "%s"; Core ignores it',
                    $package->name,
                    $package->type ?? '(none)',
                    ManifestSchema::PACKAGE_TYPE,
                );

                continue;
            }

            if (null === $package->composer) {
                $violations[] = new SolutionViolation($package->name, 'composer.json', 'is not valid JSON');

                continue;
            }

            $result = $this->parser->parse($package->composer);

            if (null === $result->manifest) {
                foreach ($result->violations as $violation) {
                    $violations[] = new SolutionViolation($package->name, $violation->field, $violation->message);
                }

                continue;
            }

            $problem = $this->providerProblem($result->manifest->provider);

            if (null !== $problem) {
                $violations[] = new SolutionViolation($package->name, 'extra.fapost.provider', $problem);

                continue;
            }

            $byId[$result->manifest->id][] = InstalledSolution::fromManifest($result->manifest, $package->version, $package->composerPath);
        }

        $valid = [];

        foreach ($byId as $id => $solutions) {
            if (count($solutions) > 1) {
                $names = array_map(static fn (InstalledSolution $solution): string => $solution->package, $solutions);
                sort($names);

                $violations[] = new SolutionViolation(
                    implode(', ', $names),
                    'extra.fapost.id',
                    sprintf('"%s" is declared by %s', $id, 2 === count($names) ? 'both packages' : 'all ' . count($names) . ' packages'),
                );

                continue;
            }

            $valid[] = $solutions[0];
        }

        usort($valid, static fn (InstalledSolution $a, InstalledSolution $b): int => $a->id <=> $b->id);
        usort($violations, static fn (SolutionViolation $a, SolutionViolation $b): int => $a->line() <=> $b->line());

        return new SolutionDiscovery($valid, $violations, $warnings, $installedJsonPath, $watched);
    }

    /**
     * Loads the class (autoloading, not running the provider) and says what is wrong with it, if anything.
     */
    private function providerProblem(string $provider): ?string
    {
        try {
            if (!class_exists($provider)) {
                return sprintf('class %s does not exist or is not autoloadable', $provider);
            }

            if (!is_subclass_of($provider, AbstractSolutionServiceProvider::class)) {
                return sprintf('%s must extend %s', $provider, AbstractSolutionServiceProvider::class);
            }

            if (!(new ReflectionClass($provider))->isInstantiable()) {
                return sprintf('%s cannot be instantiated', $provider);
            }
        } catch (Throwable $e) {
            return sprintf('class %s cannot be loaded: %s', $provider, $e->getMessage());
        }

        return null;
    }
}
