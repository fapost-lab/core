<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\Console\ConsoleExtensionRegistry;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionUiBundle;
use App\Domains\Solution\DTO\SolutionUiSource;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Checks and publishes the built console UI of the installed Solutions, for `platform:update`.
 *
 * {@see self::check()} runs with the manifests, before migrations: the description of every Solution with
 * `extra.fapost.ui` holds up ({@see SolutionUiBundleReader}) and lists every component the Solution's PHP declarations
 * name (the registry is filled: artisan boots the Solution providers). {@see self::publish()} copies the static files of each
 * bundle directory (never PHP, links out of it or the package root — the reader refuses those) to
 * `public/solutions/<id>/<hash>/`, where it never changes again, and keeps the previous build beside it, so
 * a browser still holding the old page map can finish loading it; older builds are removed.
 */
final readonly class SolutionUiPublisher
{
    /** Published builds kept per Solution: the current one and the one before it. */
    public const int KEEP = 2;

    /** Names of published build directories: the bundle hash. Anything else under a Solution's directory is no build. */
    private const string BUILD_PATTERN = '/^[0-9a-f]{12}$/D';

    private const string TEMPORARY_SUFFIX = '.tmp';

    /**
     * @param  string  $publicPath  the web root; bundles go to `<publicPath>/solutions/`
     */
    public function __construct(
        private SolutionUiBundleReader $reader,
        private ConsoleExtensionRegistry $registry,
        private Filesystem $files,
        private string $publicPath,
    ) {
    }

    /**
     * @param  list<InstalledSolution>  $solutions
     *
     * @return array{sources: array<string, SolutionUiSource>, problems: list<string>} valid sources by Solution id, and every problem found
     */
    public function check(array $solutions): array
    {
        $sources  = [];
        $problems = [];

        foreach ($solutions as $solution) {
            $components = $this->registry->components($solution->id);
            $source     = $this->reader->read($solution);

            if (null === $source) {
                if ([] !== $components) {
                    $problems[] = sprintf('%s: extra.fapost.ui: its console declarations name components (%s) but the manifest lists no fapost-ui.json', $solution->id, implode(', ', $components));
                }

                continue;
            }

            if (null === $source->bundle) {
                array_push($problems, ...$source->problems);

                continue;
            }

            $missing = array_values(array_diff($components, array_keys($source->bundle->components)));

            foreach ($missing as $path) {
                $problems[] = sprintf('%s: fapost-ui.json: components: "%s::%s" is declared in PHP but not listed', $solution->id, $solution->id, $path);
            }

            if ([] === $missing) {
                $sources[$solution->id] = $source;
            }
        }

        return ['sources' => $sources, 'problems' => $problems];
    }

    /**
     * Copies each bundle that is not published yet and prunes the builds older than the previous one.
     *
     * @param  array<string, SolutionUiSource>  $sources  as {@see self::check()} returned them
     *
     * @return list<SolutionUiBundle>
     *
     * @throws RuntimeException when a bundle cannot be copied
     */
    public function publish(array $sources): array
    {
        $published = [];

        foreach ($sources as $source) {
            if (null === $source->bundle || null === $source->directory) {
                continue;
            }

            $target = $this->path($source->bundle);

            if (! $this->files->isDirectory($target)) {
                $this->copy($source->directory, $source->files, $target);
            }

            // The newest directory is the current build; the one before it is kept by its time.
            touch($target);
            $this->prune($source->bundle);

            $published[] = $source->bundle;
        }

        return $published;
    }

    public function isPublished(SolutionUiBundle $bundle): bool
    {
        return $this->files->isDirectory($this->path($bundle));
    }

    /**
     * Absolute path of a published bundle directory.
     */
    public function path(SolutionUiBundle $bundle): string
    {
        return $this->publicPath . '/' . $bundle->directory();
    }

    /**
     * Copies the files the reader allowed ({@see SolutionUiSource::$files}) into a temporary sibling and renames it, so
     * a half-copied directory is never served under its hash. Nothing else of the package reaches the web root.
     *
     * @param  list<string>  $files  relative to $from
     */
    private function copy(string $from, array $files, string $to): void
    {
        $temporary = $to . '.' . bin2hex(random_bytes(4)) . self::TEMPORARY_SUFFIX;

        $this->files->ensureDirectoryExists(dirname($to));

        try {
            foreach ($files as $file) {
                $this->files->ensureDirectoryExists(dirname($temporary . '/' . $file));

                if (! $this->files->copy($from . '/' . $file, $temporary . '/' . $file)) {
                    throw new RuntimeException(sprintf('Cannot copy %s.', $file));
                }
            }

            if (! @rename($temporary, $to)) {
                throw new RuntimeException('Cannot move the copy into place.');
            }
        } catch (RuntimeException $exception) {
            $this->files->deleteDirectory($temporary);

            throw new RuntimeException(sprintf('Cannot publish the console UI of %s to %s: %s', basename(dirname($to)), $to, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * Keeps the current build and the newest other one; removes older builds and copies a crashed run left behind.
     */
    private function prune(SolutionUiBundle $current): void
    {
        $others = [];

        foreach ($this->files->directories(dirname($this->path($current))) as $directory) {
            $name = basename($directory);

            if (str_ends_with($name, self::TEMPORARY_SUFFIX)) {
                $this->files->deleteDirectory($directory);
            } elseif ($name !== $current->hash && 1 === preg_match(self::BUILD_PATTERN, $name)) {
                $others[] = $directory;
            }
        }

        usort($others, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));

        foreach (array_slice($others, self::KEEP - 1) as $old) {
            $this->files->deleteDirectory($old);
        }
    }
}
