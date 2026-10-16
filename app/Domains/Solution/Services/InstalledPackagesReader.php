<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\InstalledPackage;
use Fapost\Foundation\Solution\Manifest\ManifestSchema;
use JsonException;

/**
 * Reads `vendor/composer/installed.json` and picks out the packages that are, or claim to be, Solutions or connectors.
 *
 * Read-only and free of the framework and the database. The package type comes from installed.json;
 * the manifest is read from the package's own composer.json, so a package linked from a local checkout
 * shows an edit at once, and falls back to the installed.json entry when the package ships none.
 */
final class InstalledPackagesReader
{
    public function __construct(private readonly string $vendorPath)
    {
    }

    public function installedJsonPath(): string
    {
        return $this->vendorPath . '/composer/installed.json';
    }

    /**
     * Packages of type `fapost-solution` or `fapost-connector`, and any other package carrying an `extra.fapost` block.
     *
     * @return list<InstalledPackage> ordered by name
     */
    public function candidates(): array
    {
        $candidates = [];

        foreach ($this->entries() as $entry) {
            $name = $entry['name'] ?? null;

            if (!is_string($name)) {
                continue;
            }

            $type = is_string($entry['type'] ?? null) ? $entry['type'] : null;

            if (ManifestSchema::PACKAGE_TYPE !== $type && ManifestSchema::CONNECTOR_PACKAGE_TYPE !== $type && !$this->declaresManifest($entry)) {
                continue;
            }

            $path     = $this->composerPath($entry);
            $composer = null !== $path ? $this->readJson($path) : $entry;

            $candidates[$name] = new InstalledPackage(
                name: $name,
                version: is_string($entry['version'] ?? null) ? $entry['version'] : null,
                type: $type,
                composer: $composer,
                composerPath: $path,
            );
        }

        ksort($candidates);

        return array_values($candidates);
    }

    /**
     * Both installed.json formats: Composer 2 wraps the list in `packages`, Composer 1 is the list.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(): array
    {
        $contents = $this->contents($this->installedJsonPath());

        if (null === $contents) {
            return [];
        }

        $decoded  = $this->decode($contents);
        $packages = is_array($decoded) ? ($decoded['packages'] ?? $decoded) : [];

        return array_values(array_filter(is_array($packages) ? $packages : [], is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function declaresManifest(array $entry): bool
    {
        $extra = $entry['extra'] ?? null;

        return is_array($extra) && array_key_exists('fapost', $extra);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function composerPath(array $entry): ?string
    {
        $installPath = $entry['install-path'] ?? null;
        $directory   = is_string($installPath)
            ? $this->vendorPath . '/composer/' . $installPath
            : $this->vendorPath . '/' . (string) $entry['name'];

        $path = $directory . '/composer.json';

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $contents = $this->contents($path);
        $decoded  = null === $contents ? null : $this->decode($contents);

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    private function contents(string $path): ?string
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return false === $contents ? null : $contents;
    }

    private function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
