<?php

declare(strict_types=1);

/**
 * Local package development linker.
 *
 * The FaPost packages (foundation, support, …) are published as standalone git
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
 * A checkout is linked only when the root manifest asks for it and accepts it:
 *
 *   - the root manifest is `composer.json`, or the file named by `COMPOSER`
 *     when it is set — `composer overlay` sets it to `composer.local.json`, so
 *     overlay packages count only while the overlay is installed;
 *   - the package must be in its `require` or `require-dev`;
 *   - a `@dev` / `dev-*` constraint, or a `path` repository pointing at the
 *     checkout (the overlay's case), is always accepted;
 *   - otherwise the checkout's latest tag (`git describe --tags --abbrev=0`,
 *     a leading `v` stripped) must satisfy the constraint (composer/semver).
 *
 * A checkout that is not required or does not satisfy the constraint is skipped
 * with a line saying why, and `vendor/` keeps Composer's locked copy. A link this
 * script made earlier for a package whose version is refused is removed, so the
 * next `composer install` puts the locked copy back — linking a checkout of
 * another major version silently breaks every contract Core relies on. A link
 * for a package the manifest does not require is removed only when Composer
 * does not list the package as installed (vendor/composer/installed.json): a
 * package installed through another manifest — the overlay, read without
 * `COMPOSER` — keeps its link.
 *
 * It is a no-op when `packages/` is absent (e.g. production, CI without the
 * package checkouts), so it is safe to run from post-install-cmd /
 * post-update-cmd unconditionally. Without `packages/` it only warns about
 * links into it that lead nowhere.
 *
 * Environment:
 *   DEV_LINK_ROOT  overrides the project root (tests).
 *   COMPOSER       the root manifest, relative to the root, as Composer reads it.
 */

$root = getenv('DEV_LINK_ROOT') ?: dirname(__DIR__);
$packagesDir = $root.'/packages';

if (! is_dir($packagesDir)) {
    fwrite(STDOUT, "[dev:link] no packages/ directory — using VCS copies\n");

    foreach (packageLinks($root) as $vendorPath => $target) {
        if (! file_exists($root.'/'.$vendorPath)) {
            fwrite(STDERR, "[dev:link] {$vendorPath} -> {$target} leads nowhere; remove it and run `composer install` to restore the locked copy\n");
        }
    }

    return;
}

$manifestName = getenv('COMPOSER') ?: 'composer.json';
$manifestPath = str_starts_with($manifestName, '/') ? $manifestName : $root.'/'.$manifestName;
$manifest = json_decode((string) @file_get_contents($manifestPath), true);

if (! is_array($manifest)) {
    fwrite(STDERR, "[dev:link] {$manifestName} is missing or not valid JSON — nothing linked\n");

    exit(1);
}

/** @var array<string, string> $requirements lower-cased package name => constraint */
$requirements = [];

foreach ([$manifest['require'] ?? [], $manifest['require-dev'] ?? []] as $section) {
    foreach ((array) $section as $package => $constraint) {
        $requirements[strtolower((string) $package)] = (string) $constraint;
    }
}

/** @var list<string> $pathRepositories real paths of the manifest's path repositories */
$pathRepositories = [];

foreach ((array) ($manifest['repositories'] ?? []) as $repository) {
    if (is_array($repository) && ($repository['type'] ?? null) === 'path' && is_string($repository['url'] ?? null)) {
        $url = $repository['url'];
        $resolved = realpath(str_starts_with($url, '/') ? $url : $root.'/'.$url);

        if ($resolved !== false) {
            $pathRepositories[] = $resolved;
        }
    }
}

loadSemver();

$installed = installedPackages($root);

$linked = 0;

foreach (glob($packagesDir.'/*', GLOB_ONLYDIR) as $sourceAbs) {
    $packageManifest = $sourceAbs.'/composer.json';

    if (! is_file($packageManifest)) {
        continue;
    }

    $name = json_decode((string) file_get_contents($packageManifest), true)['name'] ?? null;

    if (! is_string($name) || $name === '') {
        fwrite(STDERR, "[dev:link] skipped {$sourceAbs}: no package name\n");

        continue;
    }

    $vendorPath = 'vendor/'.strtolower($name);
    $vendorAbs = $root.'/'.$vendorPath;

    // Relative target so the link resolves on host and inside the container
    // alike (absolute container paths like /shared/httpd/... would dangle on
    // the host and break IDE indexing).
    $relativeTarget = str_repeat('../', substr_count($vendorPath, '/')).'packages/'.basename($sourceAbs);

    $refusal = linkRefusal($name, $sourceAbs, $requirements, $pathRepositories, $manifestName);

    if ($refusal !== null) {
        fwrite(STDOUT, "[dev:link] skipped {$vendorPath}: {$refusal}\n");

        $isLink = is_link($vendorAbs) && pointsIntoPackages((string) readlink($vendorAbs));
        $required = isset($requirements[strtolower($name)]);

        if ($isLink && ! $required && isset($installed[strtolower($name)])) {
            fwrite(STDOUT, "[dev:link] kept {$vendorPath}: Composer installed it through another manifest (the overlay?); run with COMPOSER=composer.local.json to check it against that one\n");
        } elseif ($isLink) {
            $target = (string) readlink($vendorAbs);

            if (@unlink($vendorAbs)) {
                fwrite(STDOUT, "[dev:link] removed {$vendorPath} -> {$target}".($required
                    ? "; run `composer install` to restore the locked copy\n"
                    : "; Composer does not list it as installed\n"));
            } else {
                fwrite(STDERR, "[dev:link] failed to remove {$vendorPath} -> {$target}\n");
            }
        }

        continue;
    }

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
    fwrite(STDOUT, "[dev:link] no local package sources linked — using VCS copies\n");
}

/**
 * Why the checkout at $sourceAbs must not be linked, or null when it may be.
 *
 * @param  array<string, string>  $requirements
 * @param  list<string>  $pathRepositories
 */
function linkRefusal(string $name, string $sourceAbs, array $requirements, array $pathRepositories, string $manifestName): ?string
{
    $constraint = $requirements[strtolower($name)] ?? null;

    if ($constraint === null) {
        return "{$name} is not required by {$manifestName}";
    }

    if (str_starts_with($constraint, 'dev-') || str_contains($constraint, '@dev')) {
        return null;
    }

    if (in_array(realpath($sourceAbs), $pathRepositories, true)) {
        return null;
    }

    $tag = checkoutTag($sourceAbs);

    if ($tag === null) {
        return "the checkout has no release tag to check against {$constraint}";
    }

    if (! class_exists(Composer\Semver\Semver::class)) {
        return "composer/semver is not installed, so {$tag} cannot be checked against {$constraint}";
    }

    try {
        $satisfied = Composer\Semver\Semver::satisfies(preg_replace('/^v/i', '', $tag), $constraint);
    } catch (UnexpectedValueException) {
        return "the checkout's {$tag} cannot be checked against {$constraint}";
    }

    return $satisfied ? null : "the checkout is at {$tag}, which does not satisfy {$constraint}";
}

/**
 * The latest tag reachable from the checkout's HEAD, or null without one.
 */
function checkoutTag(string $sourceAbs): ?string
{
    $output = [];
    $exitCode = 1;

    exec('git -C '.escapeshellarg($sourceAbs).' describe --tags --abbrev=0 2>/dev/null', $output, $exitCode);

    $tag = trim($output[0] ?? '');

    return $exitCode === 0 && $tag !== '' ? $tag : null;
}

/**
 * Loads composer/semver from this project's vendor/ alone. The full Composer
 * autoloader is avoided on purpose: it requires every package's `files`, and a
 * vendor/ whose package links lead nowhere would fail on the first of them.
 */
function loadSemver(): void
{
    $source = dirname(__DIR__).'/vendor/composer/semver/src/';

    spl_autoload_register(static function (string $class) use ($source): void {
        if (str_starts_with($class, 'Composer\\Semver\\')) {
            $file = $source.str_replace('\\', '/', substr($class, strlen('Composer\\Semver\\'))).'.php';

            if (is_file($file)) {
                require $file;
            }
        }
    });
}

/**
 * Lower-cased names of the packages Composer lists as installed, as keys.
 *
 * @return array<string, true>
 */
function installedPackages(string $root): array
{
    $installed = json_decode((string) @file_get_contents($root.'/vendor/composer/installed.json'), true);
    $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
    $names = [];

    foreach ((array) $packages as $package) {
        if (is_array($package) && is_string($package['name'] ?? null)) {
            $names[strtolower($package['name'])] = true;
        }
    }

    return $names;
}

/**
 * Every vendor/<vendor>/<name> that is a symlink into packages/, existing or
 * not, as vendor path => link target.
 *
 * @return array<string, string>
 */
function packageLinks(string $root): array
{
    $links = [];

    foreach (glob($root.'/vendor/*/*') ?: [] as $path) {
        if (is_link($path) && pointsIntoPackages($target = (string) readlink($path))) {
            $links[substr($path, strlen($root) + 1)] = $target;
        }
    }

    return $links;
}

function pointsIntoPackages(string $target): bool
{
    return preg_match('#(^|/)packages/#', $target) === 1;
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
