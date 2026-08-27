<?php

declare(strict_types=1);

/**
 * Local package development linker.
 *
 * The FAPost packages (foundation, support, …) are published as standalone git
 * repositories and consumed by Core through VCS repositories + version
 * constraints in composer.json. That keeps composer.json / composer.lock
 * production-safe: a prod `composer install` pulls the packages from GitHub,
 * and the lock pins an exact commit — no `packages/` directory required.
 *
 * For local development the sources live inside `packages/` (their own nested
 * git repos, ignored by the Core repo). This script replaces the Composer-
 * installed copies under `vendor/*` with symlinks to those local sources, so
 * edits are picked up instantly with no `composer update`.
 *
 * Packages are auto-discovered: every `packages/<dir>/composer.json` is read
 * and its `name` maps the source to `vendor/<name>`. A new package therefore
 * needs no change here — only its VCS repository + require entry in the root
 * composer.json.
 *
 * It is a no-op when `packages/` is absent (e.g. production, CI without the
 * package checkouts), so it is safe to run from post-install-cmd /
 * post-update-cmd unconditionally.
 */

$root = dirname(__DIR__);
$packagesDir = $root.'/packages';

if (! is_dir($packagesDir)) {
    fwrite(STDOUT, "[dev:link] no packages/ directory — using VCS copies\n");

    return;
}

$linked = 0;

foreach (glob($packagesDir.'/*', GLOB_ONLYDIR) as $sourceAbs) {
    $manifest = $sourceAbs.'/composer.json';

    if (! is_file($manifest)) {
        continue;
    }

    $name = json_decode((string) file_get_contents($manifest), true)['name'] ?? null;

    if (! is_string($name) || $name === '') {
        fwrite(STDERR, "[dev:link] skipped {$sourceAbs}: no package name\n");

        continue;
    }

    $vendorPath = 'vendor/'.$name;
    $vendorAbs = $root.'/'.$vendorPath;

    // Relative target so the link resolves on host and inside the container
    // alike (absolute container paths like /shared/httpd/... would dangle on
    // the host and break IDE indexing).
    $relativeTarget = str_repeat('../', substr_count($vendorPath, '/')).'packages/'.basename($sourceAbs);

    // Already the correct symlink → nothing to do.
    if (is_link($vendorAbs) && readlink($vendorAbs) === $relativeTarget) {
        $linked++;

        continue;
    }

    // Remove whatever Composer placed there (real dir, stale link, …).
    if (is_link($vendorAbs)) {
        @unlink($vendorAbs);
    } elseif (is_dir($vendorAbs)) {
        removeDirectory($vendorAbs);
    }

    if (! is_dir(dirname($vendorAbs))) {
        @mkdir(dirname($vendorAbs), 0777, true);
    }

    if (@symlink($relativeTarget, $vendorAbs)) {
        $linked++;
        fwrite(STDOUT, "[dev:link] {$vendorPath} -> {$relativeTarget}\n");
    } else {
        fwrite(STDERR, "[dev:link] failed to symlink {$vendorPath}\n");
    }
}

if ($linked === 0) {
    fwrite(STDOUT, "[dev:link] no local package sources found — using VCS copies\n");
}

/**
 * Recursively delete a directory.
 */
function removeDirectory(string $dir): void
{
    $items = scandir($dir);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir.'/'.$item;

        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            removeDirectory($path);
        }
    }

    @rmdir($dir);
}
