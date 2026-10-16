<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

use Fapost\Foundation\Connector\Manifest\ConnectorManifest;

/**
 * A connector installed in this image whose manifest is valid.
 */
final readonly class InstalledConnector
{
    /**
     * @param  string  $id  stable key of the connector; never changes
     * @param  list<string>  $channels  channel types the connector declares
     * @param  string|null  $composerPath  composer.json the manifest was read from; the cache watches it
     */
    public function __construct(
        public string $package,
        public string $id,
        public string $name,
        public ?string $description,
        public string $provider,
        public array $channels,
        public ?string $version,
        public ?string $composerPath,
    ) {
    }

    public static function fromManifest(ConnectorManifest $manifest, ?string $version, ?string $composerPath): self
    {
        return new self(
            package: $manifest->package,
            id: $manifest->id,
            name: $manifest->name,
            description: $manifest->description,
            provider: $manifest->provider,
            channels: $manifest->channels,
            version: $version,
            composerPath: $composerPath,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            package: (string) $data['package'],
            id: (string) $data['id'],
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            provider: (string) $data['provider'],
            channels: array_values(array_map(strval(...), (array) $data['channels'])),
            version: isset($data['version']) ? (string) $data['version'] : null,
            composerPath: isset($data['composerPath']) ? (string) $data['composerPath'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package'      => $this->package,
            'id'           => $this->id,
            'name'         => $this->name,
            'description'  => $this->description,
            'provider'     => $this->provider,
            'channels'     => $this->channels,
            'version'      => $this->version,
            'composerPath' => $this->composerPath,
        ];
    }
}
