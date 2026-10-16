<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\ConnectorDiscovery;
use App\Domains\Solution\DTO\InstalledConnector;
use App\Domains\Solution\DTO\InstalledPackage;
use App\Domains\Solution\DTO\SolutionViolation;
use Fapost\Foundation\Connector\Manifest\ConnectorManifestParser;
use Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider;
use Fapost\Foundation\Solution\Manifest\ManifestSchema;
use ReflectionClass;
use Throwable;

/**
 * Applies the Foundation connector manifest rules to every candidate of type `fapost-connector`, then the checks that
 * need the whole installation: the provider class exists and is a connector provider, no two connectors claim the same
 * id, and no channel type is declared by two connectors. Fails closed: a package with a violation, and every package
 * of a collision, never reach `valid`.
 */
final class ConnectorManifestValidator
{
    public function __construct(private readonly ConnectorManifestParser $parser)
    {
    }

    /**
     * @param  list<InstalledPackage>  $packages  every candidate; packages of other types are skipped
     */
    public function validate(array $packages): ConnectorDiscovery
    {
        $violations = [];
        $watched    = [];
        $passed     = [];

        foreach ($packages as $package) {
            if (ManifestSchema::CONNECTOR_PACKAGE_TYPE !== $package->type) {
                continue;
            }

            if (null !== $package->composerPath) {
                $watched[] = $package->composerPath;
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

            $passed[] = InstalledConnector::fromManifest($result->manifest, $package->version, $package->composerPath);
        }

        $byId      = [];
        $byChannel = [];

        foreach ($passed as $connector) {
            $byId[$connector->id][] = $connector;

            foreach (array_unique($connector->channels) as $channel) {
                $byChannel[$channel][] = $connector;
            }
        }

        $rejected = [];

        foreach ([['extra.fapost.id', $byId], ['extra.fapost.channels', $byChannel]] as [$field, $groups]) {
            foreach ($groups as $key => $connectors) {
                if (count($connectors) < 2) {
                    continue;
                }

                $names = array_map(static fn (InstalledConnector $connector): string => $connector->package, $connectors);
                sort($names);

                $violations[] = new SolutionViolation(
                    implode(', ', $names),
                    $field,
                    sprintf('"%s" is declared by %s', $key, 2 === count($names) ? 'both packages' : 'all ' . count($names) . ' packages'),
                );

                foreach ($names as $name) {
                    $rejected[$name] = true;
                }
            }
        }

        $valid = array_values(array_filter($passed, static fn (InstalledConnector $connector): bool => !isset($rejected[$connector->package])));

        usort($valid, static fn (InstalledConnector $a, InstalledConnector $b): int => $a->id <=> $b->id);
        usort($violations, static fn (SolutionViolation $a, SolutionViolation $b): int => $a->line() <=> $b->line());

        return new ConnectorDiscovery($valid, $violations, $watched);
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

            if (!is_subclass_of($provider, AbstractConnectorServiceProvider::class)) {
                return sprintf('%s must extend %s', $provider, AbstractConnectorServiceProvider::class);
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
