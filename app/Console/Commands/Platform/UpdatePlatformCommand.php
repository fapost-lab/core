<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Platform\PlatformMigratorInterface;
use App\Console\Platform\UnservedChannelsFinderInterface;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Commands\SolutionCommandRegistry;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Solution\DTO\ConnectorDiscovery;
use App\Domains\Solution\DTO\ExtensionDiscovery;
use App\Domains\Solution\DTO\InstalledConnector;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionDiscovery;
use App\Domains\Solution\DTO\SolutionViolation;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionUiCompatibility;
use App\Domains\Solution\Services\SolutionUiPublisher;
use Fapost\Foundation\Flow\Enums\NodeConfigIssueSeverity;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * The one command of an upgrade, in five phases. A phase that fails is reported by its number and name, and what it
 * leaves undone is said; migrations only go up, so nothing a finished phase applied is ever taken back.
 *
 * 1. Validate: the manifests and the console UI (`fapost-ui.json`, the files it lists, the components the Solution's
 *    PHP declarations name). A failure stops the command before the database, `public/` or the cache is touched.
 * 2. Boot validation: the registries this process built when it booted hold what the manifests declare (actions, node
 *    types, channel types), and no extension registered a migration path with Laravel's migrator (`loadMigrationsFrom()`
 *    would run it on the landlord, where no extension keeps tables; a Solution declares `extra.fapost.migrations`).
 *    It needs no database, so a failure here also touches nothing. `--validate-only` stops after this phase, so an
 *    extension's CI and a derived image's build can run it.
 * 3. Migrate the platform: the landlord, then every tenant's schema. A landlord failure stops everything after it. A
 *    failed tenant does not hold the others back, and the Solutions are still migrated, but the activate phase is
 *    skipped.
 * 4. Migrate the Solutions, each on its own ({@see MigrateSolutionsCommand}): a Solution's run in one tenant schema is
 *    one transaction, so a failing Solution is rolled back alone there, keeps answering `solution_not_ready` in that
 *    tenant until a run succeeds, and the platform and the other Solutions stay migrated.
 * 5. Activate: warn about channels no installed integration serves any more, publish the console UI and cache the
 *    catalog. It runs after a Solution failed too, since that Solution degrades only itself; the exit code still fails.
 */
final class UpdatePlatformCommand extends Command
{
    /** @var array<int, string> */
    private const array PHASES = [
        1 => 'validate',
        2 => 'boot validation',
        3 => 'migrate platform',
        4 => 'migrate Solutions',
        5 => 'activate',
    ];

    /** @var string */
    protected $signature = 'platform:update
        {--validate-only : Only check the installed Solutions and connectors; touch neither the database nor the cache}';

    /** @var string */
    protected $description = 'Validate installed Solutions and connectors, migrate the landlord, tenant and Solution schemas, and cache the Solution catalog';

    public function handle(
        SolutionDiscoverer $discoverer,
        SolutionCache $cache,
        PlatformMigratorInterface $migrator,
        ActionHandlerRegistry $actions,
        NodeHandlerRegistryInterface $nodes,
        CommandActionTypeRegistry $commandActionTypes,
        SolutionCommandRegistry $commands,
        BuiltinCommandsRegistry $builtinCommands,
        SolutionUiPublisher $publisher,
        PublishedSolutionUi $published,
        SolutionUiCompatibility $compatibility,
        ConnectorChannels $connectorChannels,
        UnservedChannelsFinderInterface $unserved,
        Migrator $schemaMigrator,
    ): int {
        $this->phase(1, 'manifests and console UI');

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
            return $this->refuse(1, sprintf('%d console UI problem(s). Nothing was changed.', count($ui['problems'])), $ui['problems']);
        }

        $this->components->info(sprintf('Solution manifests are valid (%d installed).', count($discovery->valid)));
        $this->reportSolutions($discovery);

        $this->components->info(sprintf('Connector manifests are valid (%d installed).', count($all->connectors->valid)));
        $this->reportConnectors($all->connectors);

        $this->phase(2, 'registries and migration paths');

        $unregistered = [
            ...$this->unregisteredActions($discovery, $actions, $nodes),
            ...$this->unregisteredCommands($discovery, $commandActionTypes, $commands, $builtinCommands),
        ];

        if ([] !== $unregistered) {
            return $this->refuse(2, sprintf('%d declared action(s), node type(s) and command(s) are not registered or not valid. Nothing was changed.', count($unregistered)), $unregistered);
        }

        $unregisteredChannels = $this->unregisteredChannels($all->connectors, $connectorChannels);

        if ([] !== $unregisteredChannels) {
            return $this->refuse(2, sprintf('%d declared channel type(s) are not registered. Nothing was changed.', count($unregisteredChannels)), $unregisteredChannels);
        }

        $landlordPaths = $this->extensionMigrationPaths($all, $schemaMigrator);

        if ([] !== $landlordPaths) {
            return $this->refuse(2, sprintf('%d extension migration path(s) were registered with the landlord migrator. Nothing was changed.', count($landlordPaths)), $landlordPaths);
        }

        if ((bool) $this->option('validate-only')) {
            return self::SUCCESS;
        }

        $this->phase(3, 'the landlord, then every tenant schema');

        if (!$migrator->migrateLandlord($this->output)) {
            return $this->refuse(3, 'Landlord migrations failed. Nothing after them ran: no tenant or Solution was migrated, and the Solution catalog was not cached; fix the failure and run the update again.');
        }

        $tenantsMigrated = $migrator->migrateTenants($this->output);

        if (!$tenantsMigrated) {
            $this->components->error(sprintf('Phase 3 (%s): tenant migrations failed for at least one tenant (listed above); the other tenants are migrated.', self::PHASES[3]));
        }

        $this->phase(4, 'each Solution on its own');

        $failedSolutions = $migrator->migrateSolutions($this->solutionsWithMigrations($discovery), $this->output);

        foreach ($failedSolutions as $solutionId) {
            $this->components->error(sprintf(
                'Phase 4 (%s): Solution "%s" failed to migrate in at least one tenant (the ✘ lines above name them). A migration that failed was rolled back in its tenant schema, where the Solution answers solution_not_ready until a run succeeds; the platform and the other Solutions stay migrated.',
                self::PHASES[4],
                $solutionId,
            ));
        }

        // Each Solution's pass walks every tenant, so a tenant that cannot be switched into or read fails all of them.
        if (!$tenantsMigrated && [] !== $failedSolutions) {
            $this->components->warn('A tenant that failed in phase 3 fails every Solution\'s pass as well: a ✘ line that names a tenant and no Solution is that tenant failing, not the Solution.');
        }

        if (!$tenantsMigrated) {
            return $this->refuse(3, 'The update stopped before activation: the console UI was not published and the Solution catalog was not cached; fix the failed tenants and run the update again.');
        }

        $this->phase(5, 'publish the console UI and cache the catalog');

        // Never a refusal: the channels stay, unavailable, and come back when the connector is installed again.
        foreach ($unserved->find() as $row) {
            $this->components->warn(sprintf(
                'Tenant "%s" has %d channel(s) of type "%s", which no installed integration serves; they are unavailable until its connector is installed again.',
                $row['tenant'],
                $row['count'],
                $row['type'],
            ));
        }

        $bundles = $publisher->publish($ui['sources']);

        if ([] !== $bundles) {
            $this->components->info(sprintf('Console UI published (%d Solution(s)).', count($bundles)));
        }

        $computed = $published->compute($discovery->valid);
        $cache->write($discovery, array_values($computed['bundles']), $computed['watched'], $all->connectors);
        $this->components->info('Solution catalog cached.');
        $this->line('Restart the queue workers so they run the new code: php artisan horizon:terminate');

        if ([] !== $failedSolutions) {
            return $this->refuse(4, sprintf(
                'The update finished for everything else, but %d Solution(s) failed to migrate: %s. Fix them and run the update again.',
                count($failedSolutions),
                implode(', ', $failedSolutions),
            ));
        }

        return self::SUCCESS;
    }

    private function phase(int $number, string $what): void
    {
        $this->newLine();
        $this->line(sprintf('<options=bold>Phase %d of %d: %s</> (%s)', $number, count(self::PHASES), self::PHASES[$number], $what));
    }

    /**
     * Reports a failed phase by its number and name, with one indented line per problem.
     *
     * @param  list<string>  $lines
     */
    private function refuse(int $phase, string $message, array $lines = []): int
    {
        $this->components->error(sprintf('Phase %d (%s): %s', $phase, self::PHASES[$phase], $message));

        foreach ($lines as $line) {
            $this->line('  ' . $line);
        }

        return self::FAILURE;
    }

    /**
     * The Solutions whose package ships migrations: one without them has no tables to bring current.
     *
     * @return list<string>
     */
    private function solutionsWithMigrations(SolutionDiscovery $discovery): array
    {
        $ids = [];

        foreach ($discovery->valid as $solution) {
            if (null !== $solution->migrationsDigest) {
                $ids[] = $solution->id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * The manifest is the static truth for the catalog and the activation screen; a Solution that declares an
     * action its provider never registered would offer a flow author something that always fails.
     *
     * @return list<string>
     */
    private function unregisteredActions(SolutionDiscovery $discovery, ActionHandlerRegistry $actions, NodeHandlerRegistryInterface $nodes): array
    {
        $lines = [];

        foreach ($discovery->valid as $solution) {
            foreach ($solution->actions as $index => $actionId) {
                if (!$actions->has($actionId)) {
                    $lines[] = sprintf('%s: extra.fapost.actions[%d]: "%s" is declared but not registered', $solution->package, $index, $actionId);
                }
            }

            foreach ($solution->nodes as $index => $nodeType) {
                if ($nodes->ownerOf($nodeType) !== $solution->id) {
                    $lines[] = sprintf('%s: extra.fapost.nodes[%d]: "%s" is declared but not registered', $solution->package, $index, $nodeType);
                }
            }
        }

        return $lines;
    }

    /**
     * Command action types and chat commands the manifests declare but the providers never registered, and a Solution
     * command whose config its own action type refuses: a tenant would type it and get nothing.
     *
     * @return list<string>
     */
    private function unregisteredCommands(SolutionDiscovery $discovery, CommandActionTypeRegistry $types, SolutionCommandRegistry $commands, BuiltinCommandsRegistry $builtins): array
    {
        $lines = [];

        foreach ($discovery->valid as $solution) {
            foreach ($solution->commandTypes as $index => $type) {
                if ($types->ownerOf($type) !== $solution->id) {
                    $lines[] = sprintf('%s: extra.fapost.command_types[%d]: "%s" is declared but not registered', $solution->package, $index, $type);
                }
            }

            foreach ($solution->commands as $index => $name) {
                // A command Core took as built-in after the Solution declared it is skipped at registration, not fatal.
                if ($builtins->has($name)) {
                    continue;
                }

                if ($commands->ownerOf($name) !== $solution->id) {
                    $lines[] = sprintf('%s: extra.fapost.commands[%d]: "%s" is declared but not registered', $solution->package, $index, $name);

                    continue;
                }

                $command    = $commands->get($name);
                $actionType = $command->actionType();

                if (null !== CommandActionType::tryFrom($actionType)) {
                    continue;
                }

                if ($types->ownerOf($actionType) !== $solution->id) {
                    $lines[] = sprintf('%s: extra.fapost.commands[%d]: "%s" takes the action type "%s", which the Solution does not register', $solution->package, $index, $name, $actionType);

                    continue;
                }

                foreach ($types->get($actionType)->validateConfig($command->config()) as $issue) {
                    if (NodeConfigIssueSeverity::Error === $issue->severity) {
                        $lines[] = sprintf('%s: extra.fapost.commands[%d]: "%s": %s', $solution->package, $index, $name, $issue->message);
                    }
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

        $this->refuse(1, sprintf('%d manifest problem(s). Nothing was changed.', count($violations)), array_map(
            static fn (SolutionViolation $violation): string => $violation->line(),
            $violations,
        ));
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

    /**
     * Migration paths registered with Laravel's migrator that lie inside an installed Solution or connector package.
     *
     * @return list<string>
     */
    private function extensionMigrationPaths(ExtensionDiscovery $all, Migrator $migrator): array
    {
        /** @var array<string, string> $roots package directory by package name */
        $roots = [];

        foreach ([...$all->solutions->valid, ...$all->connectors->valid] as $extension) {
            $root = null === $extension->composerPath ? false : realpath(dirname($extension->composerPath));

            if (false !== $root) {
                $roots[$extension->package] = $root;
            }
        }

        $lines = [];

        foreach ($migrator->paths() as $path) {
            $real = realpath($path);

            foreach (false === $real ? [] : $roots as $package => $root) {
                if (str_starts_with($real . '/', $root . '/')) {
                    $lines[] = sprintf('%s: "%s" is registered with loadMigrationsFrom(); a Solution declares extra.fapost.migrations, which run in tenant schemas', $package, $path);
                }
            }
        }

        sort($lines);

        return $lines;
    }
}
