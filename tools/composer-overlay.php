<?php

declare(strict_types=1);

/**
 * Composer overlay.
 *
 * Adds Composer packages on top of Core without Core's composer.json or
 * composer.lock naming them. The overlay is described by an untracked
 * `composer.overlay.json` at the project root:
 *
 *     {
 *         "repositories": [{ "type": "path", "url": "packages/example" }],
 *         "require": { "vendor/name": "@dev" }
 *     }
 *
 * Only `repositories` and `require` are accepted. A package Core already requires
 * (require or require-dev) or locks (composer.lock, directly or transitively) is
 * rejected: an overlay adds packages, it never overrides or moves Core's.
 *
 * The script writes `composer.local.json` (Core's composer.json plus the overlay),
 * copies composer.lock to `composer.local.lock`, and runs
 * `composer update <overlay packages>` against them with COMPOSER=composer.local.json.
 * `--with-dependencies` is deliberately not used, so Core's locked versions stay
 * as they are and only new transitive packages may be added.
 *
 * Usage: php tools/composer-overlay.php [--dry-run] [--require-overlay] [<composer update args>]
 *
 * Own options are `--dry-run` (write the files, skip Composer) and
 * `--require-overlay` (fail when composer.overlay.json is absent, for CI and
 * images where a lost overlay must not pass silently). They are recognised
 * anywhere; every `--` token is skipped and everything else is passed to
 * `composer update`. Run through Composer (`composer overlay -- --dry-run`),
 * note that Composer strips the first `--` and drops unknown options before it.
 *
 * Environment:
 *   OVERLAY_ROOT      overrides the project root (tests).
 *   OVERLAY_COMPOSER  explicit Composer executable.
 *   COMPOSER_BINARY   exported by Composer to its scripts; used (through PHP)
 *                     when it is a file, as it may be a non-executable phar.
 *   Otherwise `composer` from PATH.
 */

$root = getenv('OVERLAY_ROOT') ?: dirname(__DIR__);

$dryRun         = false;
$requireOverlay = false;
$passthrough    = [];

foreach (array_slice($argv, 1) as $argument) {
    if ('--dry-run' === $argument) {
        $dryRun = true;
    } elseif ('--require-overlay' === $argument) {
        $requireOverlay = true;
    } elseif ('--' !== $argument) {
        $passthrough[] = $argument;
    }
}

$overlayPath = $root . '/composer.overlay.json';

if (! is_file($overlayPath)) {
    if ($requireOverlay) {
        overlayFail('composer.overlay.json is required (--require-overlay) but missing');
    }

    fwrite(STDOUT, "[overlay] no composer.overlay.json — nothing to overlay\n");

    exit(0);
}

$overlay = json_decode((string) file_get_contents($overlayPath), false);

if (! $overlay instanceof stdClass) {
    overlayFail('composer.overlay.json is not a valid JSON object');
}

foreach (array_keys((array) $overlay) as $key) {
    if (! in_array($key, ['repositories', 'require'], true)) {
        overlayFail("unsupported key \"{$key}\" in composer.overlay.json (allowed: repositories, require)");
    }
}

$repositories = $overlay->repositories ?? [];
$require      = $overlay->require ?? null;

if (! is_array($repositories)) {
    overlayFail('"repositories" in composer.overlay.json must be a list');
}

if (! $require instanceof stdClass || [] === (array) $require) {
    overlayFail('"require" in composer.overlay.json must be an object listing at least one package');
}

foreach ((array) $require as $package => $constraint) {
    if (! is_string($constraint)) {
        overlayFail("constraint of \"{$package}\" in composer.overlay.json must be a string");
    }
}

$corePath = $root . '/composer.json';
$local    = json_decode((string) @file_get_contents($corePath), false);

if (! $local instanceof stdClass) {
    overlayFail('composer.json is missing or not valid JSON');
}

$coreNames = [];

foreach ([$local->require ?? null, $local->{'require-dev'} ?? null] as $section) {
    foreach (array_keys((array) $section) as $name) {
        $coreNames[mb_strtolower((string) $name)] = 'Core requires it';
    }
}

$lockPath = $root . '/composer.lock';
$lock     = is_file($lockPath) ? json_decode((string) file_get_contents($lockPath), true) : null;

foreach (['packages', 'packages-dev'] as $section) {
    foreach ($lock[$section] ?? [] as $locked) {
        if (isset($locked['name'])) {
            $coreNames[mb_strtolower((string) $locked['name'])] = 'it is locked by Core';
        }
    }
}

$packages = array_map('strval', array_keys((array) $require));

foreach ($packages as $package) {
    if (isset($coreNames[mb_strtolower($package)])) {
        overlayFail("\"{$package}\" cannot be overlaid: {$coreNames[mb_strtolower($package)]}; an overlay adds packages, it never overrides them");
    }
}

// Overlay repositories go first so they take priority over Core's. Everything
// stays decoded as objects so empty JSON objects ({}) survive the round trip.
$local->repositories = array_merge($repositories, array_values((array) ($local->repositories ?? [])));
$local->require      = (object) array_merge((array) ($local->require ?? new stdClass()), (array) $require);

file_put_contents(
    $root . '/composer.local.json',
    json_encode($local, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

if (is_file($lockPath)) {
    copy($lockPath, $root . '/composer.local.lock');
} elseif (is_file($root . '/composer.local.lock')) {
    unlink($root . '/composer.local.lock');
}

fwrite(STDOUT, '[overlay] composer.local.json written for: ' . implode(', ', $packages) . "\n");

if ($dryRun) {
    exit(0);
}

$composerBinary = getenv('COMPOSER_BINARY');

if (is_string(getenv('OVERLAY_COMPOSER')) && '' !== getenv('OVERLAY_COMPOSER')) {
    $composer = [getenv('OVERLAY_COMPOSER')];
} elseif (is_string($composerBinary) && is_file($composerBinary)) {
    $composer = [PHP_BINARY, $composerBinary];
} else {
    $composer = ['composer'];
}

$process = proc_open(
    array_merge($composer, ['update'], $packages, $passthrough),
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $root,
    array_merge(getenv(), ['COMPOSER' => 'composer.local.json']),
);

if (! is_resource($process)) {
    overlayFail('could not start Composer');
}

$exitCode = proc_close($process);

if (0 !== $exitCode) {
    exit($exitCode);
}

fwrite(STDOUT, "[overlay] done — run further Composer commands with COMPOSER=composer.local.json; plain composer commands restore Core without the overlay\n");

function overlayFail(string $message): never
{
    fwrite(STDERR, "[overlay] {$message}\n");

    exit(1);
}
