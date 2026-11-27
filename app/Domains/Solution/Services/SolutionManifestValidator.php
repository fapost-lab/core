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
 * claim the same id or the same chat command (commands carry no id prefix, since a contact types
 * them). Fails closed: a package with a violation, and both packages of a collision, never reach
 * `valid`.
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

            // A connector is validated by ConnectorManifestValidator; its composer.json is still watched above.
            if (ManifestSchema::CONNECTOR_PACKAGE_TYPE === $package->type) {
                continue;
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

            $migrations = $this->migrationsProblem($result->manifest->migrations, $package->composerPath);

            if (is_string($migrations)) {
                $violations[] = new SolutionViolation($package->name, 'extra.fapost.migrations', $migrations);

                continue;
            }

            $byId[$result->manifest->id][] = InstalledSolution::fromManifest($result->manifest, $package->version, $package->composerPath, $migrations['latest'] ?? null, $migrations['digest'] ?? null);
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

        [$valid, $commandViolations] = $this->withoutCommandCollisions($valid);
        $violations                  = [...$violations, ...$commandViolations];

        usort($valid, static fn (InstalledSolution $a, InstalledSolution $b): int => $a->id <=> $b->id);
        usort($violations, static fn (SolutionViolation $a, SolutionViolation $b): int => $a->line() <=> $b->line());

        return new SolutionDiscovery($valid, $violations, $warnings, $installedJsonPath, $watched);
    }

    /**
     * Leaves out every Solution that declares a chat command another valid Solution declares too.
     *
     * @param  list<InstalledSolution>  $solutions
     * @return array{0: list<InstalledSolution>, 1: list<SolutionViolation>}
     */
    private function withoutCommandCollisions(array $solutions): array
    {
        $claims = [];

        foreach ($solutions as $solution) {
            foreach ($solution->commands as $command) {
                $claims[$command][] = $solution->package;
            }
        }

        $violations = [];
        $colliding  = [];

        foreach ($claims as $command => $packages) {
            if (count($packages) < 2) {
                continue;
            }

            sort($packages);
            $colliding = [...$colliding, ...$packages];

            $violations[] = new SolutionViolation(
                implode(', ', $packages),
                'extra.fapost.commands',
                sprintf('"%s" is declared by %s', $command, 2 === count($packages) ? 'both packages' : 'all ' . count($packages) . ' packages'),
            );
        }

        if ([] === $colliding) {
            return [$solutions, []];
        }

        return [
            array_values(array_filter($solutions, static fn (InstalledSolution $solution): bool => !in_array($solution->package, $colliding, true))),
            $violations,
        ];
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

    /**
     * Checks the directory `extra.fapost.migrations` names: it exists inside the package and does not lead out of it.
     * Answers the problem as a string, or the newest migration in it (file name without `.php`) and the digest of every name
     * in it (both null when it holds none);
     * null when the Solution declares no migrations or its package directory is unknown.
     *
     * @return string|array{latest: string|null, digest: string|null}|null
     */
    private function migrationsProblem(?string $migrations, ?string $composerPath): string|array|null
    {
        if (null === $migrations || null === $composerPath) {
            return null;
        }

        $root      = realpath(dirname($composerPath));
        $directory = realpath(dirname($composerPath) . '/' . $migrations);

        if (false === $directory || !is_dir($directory)) {
            return sprintf('"%s" is not a directory in the package', $migrations);
        }

        if (false === $root || !str_starts_with($directory . '/', $root . '/') || $directory === $root) {
            return sprintf('"%s" leads out of the package', $migrations);
        }

        $names = array_map(static fn (string $file): string => basename($file, '.php'), glob($directory . '/*.php') ?: []);
        sort($names);

        return [] === $names
            ? ['latest' => null, 'digest' => null]
            : ['latest' => $names[array_key_last($names)], 'digest' => InstalledSolution::digestOf($names)];
    }
}
