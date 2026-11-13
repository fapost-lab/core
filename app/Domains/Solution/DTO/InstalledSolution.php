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
     * @param  bool  $state  whether the manifest declares the Solution owns the flow state namespace named by its id (`extra.fapost.state`)
     * @param  string|null  $composerPath  composer.json the manifest was read from; the cache watches it
     * @param  string|null  $ui  `extra.fapost.ui`: the Solution's `fapost-ui.json` relative to the package root, or null
     *                           when it adds no console UI
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
    ) {
    }

    public static function fromManifest(SolutionManifest $manifest, ?string $version, ?string $composerPath): self
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
        );
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
        ];
    }
}
