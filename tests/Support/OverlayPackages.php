<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Solution\Services\InstalledPackagesReader;

/**
 * The packages installed on top of Core's `composer.lock`: what `composer overlay` added, with the dependencies it
 * brought in, and nothing Core locks (tools/composer-overlay.php refuses to overlay those and writes its own lock).
 *
 * Core's tests describe Core alone, so they leave these packages out ({@see TestCase::createApplication()}). Where no
 * overlay is installed, CI included, the list is empty.
 */
final class OverlayPackages
{
    /**
     * Results already computed in this process, by vendor path and the state of installed.json and composer.lock;
     * the application is created anew for every test.
     *
     * @var array<string, list<string>>
     */
    private static array $known = [];

    /**
     * Names in `<vendor>/composer/installed.json` that `<base>/composer.lock` does not list; empty when either file is
     * missing or unreadable, as there is then nothing to tell an overlay by.
     *
     * @return list<string>
     */
    public static function in(string $basePath, string $vendorPath): array
    {
        $installedPath = $vendorPath . '/composer/installed.json';
        $lockPath      = $basePath . '/composer.lock';
        $key           = implode('|', [$vendorPath, $basePath, self::stamp($installedPath), self::stamp($lockPath)]);

        return self::$known[$key] ??= self::compute($installedPath, $lockPath);
    }

    /**
     * @return list<string>
     */
    private static function compute(string $installedPath, string $lockPath): array
    {
        $lock = self::decode($lockPath);

        if (null === $lock || !is_file($installedPath)) {
            return [];
        }

        $locked = [];

        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $locked[mb_strtolower($package['name'])] = true;
            }
        }

        $overlay = [];

        foreach ((new InstalledPackagesReader(dirname($installedPath, 2)))->entries() as $package) {
            $name = $package['name'] ?? null;

            if (is_string($name) && !isset($locked[mb_strtolower($name)])) {
                $overlay[] = $name;
            }
        }

        sort($overlay);

        return $overlay;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(string $path): ?array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        $decoded  = false === $contents ? null : json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function stamp(string $path): string
    {
        clearstatcache(true, $path);

        return is_file($path) ? filesize($path) . ':' . filemtime($path) : '-';
    }
}
