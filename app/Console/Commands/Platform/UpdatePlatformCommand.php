<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Platform\PlatformMigratorInterface;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Solution\DTO\ConnectorDiscovery;
use App\Domains\Solution\DTO\ExtensionDiscovery;
use App\Domains\Solution\DTO\InstalledConnector;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionDiscovery;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionUiCompatibility;
use App\Domains\Solution\Services\SolutionUiPublisher;
use Illuminate\Console\Command;

/**
 * The one command of an upgrade: check the installed Solutions, migrate, publish their console UI, remember the result.
 *
 * Validation comes first and decides everything: a manifest with a violation, or a console UI that does not hold up
 * (`fapost-ui.json`, the files it lists, the components the Solution's PHP declarations name), stops the command before
 * the database, `public/` or the cache is touched. `--validate-only` stops after that check and needs no database,
 * so an extension's CI and a derived image's build can run it.
 */
final class UpdatePlatformCommand extends Command
{
    /** @var string */
    protected $signature = 'platform:update
        {--validate-only : Only check the installed Solutions and connectors; touch neither the database nor the cache}';

    /** @var string */
    protected $description = 'Validate installed Solutions and connectors, migrate the landlord and tenant schemas, and cache the Solution catalog';

    public function handle(
        SolutionDiscoverer $discoverer,
        SolutionCache $cache,
        PlatformMigratorInterface $migrator,
        ActionHandlerRegistry $actions,
        SolutionUiPublisher $publisher,
        PublishedSolutionUi $published,
        SolutionUiCompatibility $compatibility,
        ConnectorChannels $connectorChannels,
    ): int {
        $all       = $discoverer->discoverAll();
        $discovery = $all->solutions;

        foreach ($discovery->warnings as $warning) {
            $this->components->warn($warning);
        }

        if (!$all->isValid()) {
            $this->reportViolations($all);

            return self::FAILURE;
        }

        $ui = $publisher->check($discovery->valid);

        foreach ($ui['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        // A bundle built for another kit, Vue or Inertia than this platform ships is a warning here and a refusal on the
        // activation screen (adr-20261014-solution-console-ui, Stability).
        foreach ($ui['sources'] as $source) {
            foreach (null === $source->bundle ? [] : $compatibility->mismatches($source->bundle) as $mismatch) {
                $this->components->warn(sprintf(
                    '%s: console UI is built for %s %s%s, this platform ships %s; the Solution cannot be activated until it is rebuilt.',
                    $source->solutionId,
                    $mismatch->component,
                    $mismatch->required,
                    $mismatch->unreadable ? ' (a range that cannot be read)' : '',
                    $mismatch->provided,
                ));
            }
        }

        if ([] !== $ui['problems']) {
            $this->components->error(sprintf('%d console UI problem(s). Nothing was changed.', count($ui['problems'])));

            foreach ($ui['problems'] as $problem) {
                $this->line('  ' . $problem);
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('Solution manifests are valid (%d installed).', count($discovery->valid)));
        $this->reportSolutions($discovery);

        $this->components->info(sprintf('Connector manifests are valid (%d installed).', count($all->connectors->valid)));
        $this->reportConnectors($all->connectors);

        $unregistered = $this->unregisteredActions($discovery, $actions);

        if ([] !== $unregistered) {
            $this->components->error(sprintf('%d declared action(s) are not registered. Nothing was changed.', count($unregistered)));

            foreach ($unregistered as $line) {
                $this->line('  ' . $line);
            }

            return self::FAILURE;
        }

        $unregisteredChannels = $this->unregisteredChannels($all->connectors, $connectorChannels);

        if ([] !== $unregisteredChannels) {
            $this->components->error(sprintf('%d declared channel type(s) are not registered. Nothing was changed.', count($unregisteredChannels)));

            foreach ($unregisteredChannels as $line) {
                $this->line('  ' . $line);
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('validate-only')) {
            return self::SUCCESS;
        }

        if (!$migrator->migrate($this->output)) {
            $this->components->error('Migrations failed. The Solution catalog was not cached; fix the failure and run the update again.');

            return self::FAILURE;
        }

        $bundles = $publisher->publish($ui['sources']);

        if ([] !== $bundles) {
            $this->components->info(sprintf('Console UI published (%d Solution(s)).', count($bundles)));
        }

        $computed = $published->compute($discovery->valid);
        $cache->write($discovery, array_values($computed['bundles']), $computed['watched'], $all->connectors);
        $this->components->info('Solution catalog cached.');
        $this->line('Restart the queue workers so they run the new code: php artisan horizon:terminate');

        return self::SUCCESS;
    }

    /**
     * The manifest is the static truth for the catalog and the activation screen; a Solution that declares an
     * action its provider never registered would offer a flow author something that always fails.
     *
     * @return list<string>
     */
    private function unregisteredActions(SolutionDiscovery $discovery, ActionHandlerRegistry $actions): array
    {
        $lines = [];

        foreach ($discovery->valid as $solution) {
            foreach ($solution->actions as $index => $actionId) {
                if (!$actions->has($actionId)) {
                    $lines[] = sprintf('%s: extra.fapost.actions[%d]: "%s" is declared but not registered', $solution->package, $index, $actionId);
                }
            }
        }

        return $lines;
    }

    /**
     * A connector that declares a channel type its provider never registered would offer a type nothing serves.
     *
     * @return list<string>
     */
    private function unregisteredChannels(ConnectorDiscovery $connectors, ConnectorChannels $registered): array
    {
        $lines = [];

        foreach ($connectors->valid as $connector) {
            foreach ($connector->channels as $type) {
                if ($connector->id !== $registered->ownerOf($type)) {
                    $lines[] = sprintf('"%s" declares channel type "%s" but does not register it', $connector->id, $type);
                }
            }
        }

        return $lines;
    }

    private function reportViolations(ExtensionDiscovery $discovery): void
    {
        $violations = $discovery->violations();

        $this->components->error(sprintf('%d manifest problem(s). Nothing was changed.', count($violations)));

        foreach ($violations as $violation) {
            $this->line('  ' . $violation->line());
        }
    }

    private function reportSolutions(SolutionDiscovery $discovery): void
    {
        if ([] === $discovery->valid) {
            return;
        }

        $this->table(
            ['Id', 'Package', 'Version', 'Requires foundation'],
            array_map(
                static fn (InstalledSolution $solution): array => [
                    $solution->id,
                    $solution->package,
                    $solution->version ?? '-',
                    $solution->foundationConstraint,
                ],
                $discovery->valid,
            ),
        );
    }

    private function reportConnectors(ConnectorDiscovery $discovery): void
    {
        if ([] === $discovery->valid) {
            return;
        }

        $this->table(
            ['Id', 'Package', 'Version', 'Channels'],
            array_map(
                static fn (InstalledConnector $connector): array => [
                    $connector->id,
                    $connector->package,
                    $connector->version ?? '-',
                    implode(', ', $connector->channels),
                ],
                $discovery->valid,
            ),
        );
    }
}
