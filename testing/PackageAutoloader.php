<?php

declare(strict_types=1);

namespace Fapost\Testing;

use RuntimeException;

/**
 * Registers a Solution package's own classes ahead of Core's autoloader.
 *
 * Composer installs the package into Core's vendor/ (usually a symlink to the main checkout), and autoloads only
 * `autoload`, never `autoload-dev`, of a non-root package. A test needs both: its `Tests\` namespace, and `src/` of the
 * directory it runs from, which in a git worktree is not the directory Core's vendor/ points at. The autoloader is
 * prepended so the worktree's classes win; in the main checkout both mappings are the same directory.
 *
 * @experimental may change in minor versions before 1.0
 */
final class PackageAutoloader
{
    public static function register(string $packageRoot): void
    {
        $composerFile = mb_rtrim($packageRoot, '/') . '/composer.json';

        if (! is_file($composerFile)) {
            throw new RuntimeException("No composer.json found in package root {$packageRoot}.");
        }

        $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        $map      = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ((array) ($composer[$section]['psr-4'] ?? []) as $prefix => $paths) {
                foreach ((array) $paths as $path) {
                    $map[(string) $prefix][] = mb_rtrim($packageRoot, '/') . '/' . mb_trim((string) $path, '/');
                }
            }
        }

        // Longest prefix first, so `Vendor\Pkg\Tests\` is tried before `Vendor\Pkg\`.
        uksort($map, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        spl_autoload_register(static function (string $class) use ($map): void {
            foreach ($map as $prefix => $directories) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = str_replace('\\', '/', mb_substr($class, mb_strlen($prefix))) . '.php';

                foreach ($directories as $directory) {
                    if (is_file($directory . '/' . $relative)) {
                        require $directory . '/' . $relative;

                        return;
                    }
                }
            }
        }, true, true);
    }
}
