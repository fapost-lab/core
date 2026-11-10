<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;

/**
 * Laravel's package discovery as Core alone has it: the overlay packages ({@see OverlayPackages}) are ignored as if
 * Core's composer.json listed them under `extra.laravel.dont-discover`.
 *
 * The manifest is kept in memory and never written: `bootstrap/cache/packages.php` belongs to the application a
 * developer runs, with its overlay, and a test run must not take the overlay's providers out of it.
 */
final class CorePackageManifest extends PackageManifest
{
    /**
     * Manifests already built in this process, by base path and overlay; the application is created anew for every
     * test.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $built = [];

    /**
     * @param  list<string>  $overlayPackages
     */
    public function __construct(Filesystem $files, string $basePath, string $manifestPath, private readonly array $overlayPackages)
    {
        parent::__construct($files, $basePath, $manifestPath);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getManifest(): array
    {
        if (null !== $this->manifest) {
            return $this->manifest;
        }

        if (!isset(self::$built[$this->key()])) {
            $this->build();
        }

        return $this->manifest = self::$built[$this->key()];
    }

    /**
     * @param  array<string, array<string, mixed>>  $manifest
     */
    protected function write(array $manifest): void
    {
        self::$built[$this->key()] = $manifest;
    }

    /**
     * @return list<string>
     */
    protected function packagesToIgnore(): array
    {
        return [...parent::packagesToIgnore(), ...$this->overlayPackages];
    }

    private function key(): string
    {
        return implode('|', [$this->basePath, $this->vendorPath, implode(',', $this->overlayPackages)]);
    }
}
