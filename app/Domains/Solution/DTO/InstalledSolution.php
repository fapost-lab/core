<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

use Fapost\Foundation\Solution\Manifest\SolutionManifest;
use InvalidArgumentException;

/**
 * A Solution installed in this image whose manifest is valid.
 */
final readonly class InstalledSolution
{
    /**
     * @param  string  $id  stable key of the Solution; never changes
     * @param  list<string>  $actions  action ids the manifest declares
     * @param  list<string>  $nodes  flow node types the manifest declares (`extra.fapost.nodes`)
     * @param  list<string>  $triggers  flow trigger types the manifest declares (`extra.fapost.triggers`)
     * @param  bool  $state  whether the manifest declares the Solution owns the flow state namespace named by its id (`extra.fapost.state`)
     * @param  string|null  $composerPath  composer.json the manifest was read from; the cache watches it
     * @param  string|null  $ui  `extra.fapost.ui`: the Solution's `fapost-ui.json` relative to the package root, or null
     *                           when it adds no console UI
     * @param  string|null  $migrations  `extra.fapost.migrations`: the directory of the Solution's tenant migrations relative to the
     *                                   package root, or null when it keeps no tables
     * @param  string|null  $latestMigration  name of the newest migration in that directory (file name without `.php`), read at
     *                                        discovery; null when there is none
     * @param  string|null  $migrationsDigest  {@see self::digestOf()} of every migration name in that directory; null when there is
     *                                         none. A tenant schema last brought up to this digest is current.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $package,
        public ?string $version,
        public string $foundationConstraint,
        public string $provider,
        public array $actions,
        public ?string $composerPath,
        public ?string $ui = null,
        public array $nodes = [],
        public bool $state = false,
        public ?string $migrations = null,
        public ?string $latestMigration = null,
        public ?string $migrationsDigest = null,
        public array $triggers = [],
    ) {
    }

    public static function fromManifest(SolutionManifest $manifest, ?string $version, ?string $composerPath, ?string $latestMigration = null, ?string $migrationsDigest = null): self
    {
        return new self(
            id: $manifest->id,
            name: $manifest->name,
            description: $manifest->description,
            package: $manifest->package,
            version: $version,
            foundationConstraint: $manifest->foundationConstraint,
            provider: $manifest->provider,
            actions: $manifest->actions,
            composerPath: $composerPath,
            ui: $manifest->ui,
            nodes: $manifest->nodes,
            state: $manifest->state,
            migrations: $manifest->migrations,
            latestMigration: $latestMigration,
            migrationsDigest: $migrationsDigest,
            triggers: $manifest->triggers,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // A cache written before Core knew node types and the state namespace says nothing about them; the
        // exception makes SolutionCache ignore it as stale instead of reading them as "none".
        if (!array_key_exists('nodes', $data) || !array_key_exists('state', $data)) {
            throw new InvalidArgumentException('The cached Solution has no nodes or state; the cache predates them.');
        }

        if (!array_key_exists('triggers', $data)) {
            throw new InvalidArgumentException('The cached Solution has no trigger types; the cache predates them.');
        }

        if (!array_key_exists('migrations', $data) || !array_key_exists('latestMigration', $data) || !array_key_exists('migrationsDigest', $data)) {
            throw new InvalidArgumentException('The cached Solution has no migrations; the cache predates them.');
        }

        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            package: (string) $data['package'],
            version: isset($data['version']) ? (string) $data['version'] : null,
            foundationConstraint: (string) $data['foundationConstraint'],
            provider: (string) $data['provider'],
            actions: array_values(array_map(strval(...), (array) $data['actions'])),
            composerPath: isset($data['composerPath']) ? (string) $data['composerPath'] : null,
            ui: isset($data['ui']) ? (string) $data['ui'] : null,
            nodes: array_values(array_map(strval(...), (array) $data['nodes'])),
            state: (bool) $data['state'],
            migrations: isset($data['migrations']) ? (string) $data['migrations'] : null,
            latestMigration: isset($data['latestMigration']) ? (string) $data['latestMigration'] : null,
            migrationsDigest: isset($data['migrationsDigest']) ? (string) $data['migrationsDigest'] : null,
            triggers: array_values(array_map(strval(...), (array) $data['triggers'])),
        );
    }

    /**
     * The digest of a set of migration names, order-independent: what discovery records for the package and what the
     * migrator records for a schema it brought current.
     *
     * @param  list<string>  $names
     */
    public static function digestOf(array $names): string
    {
        sort($names, SORT_STRING);

        return hash('sha256', implode("\n", $names));
    }

    /**
     * Absolute path of the Solution's `fapost-ui.json`, or null when it declares none or its package directory is unknown.
     */
    public function uiPath(): ?string
    {
        if (null === $this->ui || null === $this->composerPath) {
            return null;
        }

        return dirname($this->composerPath) . '/' . $this->ui;
    }

    /**
     * Absolute path of the Solution's migrations directory, or null when it declares none or its package directory is unknown.
     */
    public function migrationsPath(): ?string
    {
        if (null === $this->migrations || null === $this->composerPath) {
            return null;
        }

        return dirname($this->composerPath) . '/' . $this->migrations;
    }

    /**
     * Whether a tenant schema last brought up to `$appliedDigest` (null: never) has every migration the installed package
     * ships. The whole set is compared, not the newest name, so a file that sorts before one already applied (a branch
     * merged late) still makes the schema not current. A package moved back to fewer files is not current either until a
     * run, which applies nothing and records the smaller set.
     */
    public function isSchemaCurrent(?string $appliedDigest): bool
    {
        if (null === $this->migrationsDigest) {
            return true;
        }

        return $appliedDigest === $this->migrationsDigest;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'description'          => $this->description,
            'package'              => $this->package,
            'version'              => $this->version,
            'foundationConstraint' => $this->foundationConstraint,
            'provider'             => $this->provider,
            'actions'              => $this->actions,
            'composerPath'         => $this->composerPath,
            'ui'                   => $this->ui,
            'nodes'                => $this->nodes,
            'state'                => $this->state,
            'migrations'           => $this->migrations,
            'latestMigration'      => $this->latestMigration,
            'migrationsDigest'     => $this->migrationsDigest,
            'triggers'             => $this->triggers,
        ];
    }
}
