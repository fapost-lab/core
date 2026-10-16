<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\ConnectorDiscovery;
use App\Domains\Solution\DTO\InstalledConnector;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionDiscovery;
use App\Domains\Solution\DTO\SolutionUiBundle;
use RuntimeException;
use Throwable;

/**
 * A compiled copy of the valid Solutions, so a request does not parse installed.json.
 *
 * Only an accelerator: the file is written by `platform:update` and `solutions:cache`, and when it is
 * missing, unreadable or stale it is ignored and the catalog is built in memory. It records the size
 * and modification time of installed.json and of the composer.json of every candidate package, valid or not; any difference makes
 * it stale. A request never writes it.
 *
 * The valid connectors live in the same file under `connectors`. A cache written before connectors existed has no such
 * key and says nothing about them, so {@see connectors()} returns null for it and the list is built in memory.
 *
 * Beside the catalog it keeps the published console UI bundles ({@see PublishedSolutionUi}), so a request reads
 * neither `fapost-ui.json` nor `public/solutions/`.
 */
final class SolutionCache
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return list<InstalledSolution>|null null when there is no usable, fresh cache
     */
    public function read(): ?array
    {
        $data = $this->load();

        if (null === $data) {
            return null;
        }

        try {
            return array_values(array_map(
                static fn (mixed $solution): InstalledSolution => InstalledSolution::fromArray((array) $solution),
                $data['solutions'],
            ));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<InstalledConnector>|null null when there is no usable, fresh cache, or it was written without connectors
     */
    public function connectors(): ?array
    {
        $data = $this->load();

        if (null === $data || !is_array($data['connectors'] ?? null)) {
            return null;
        }

        try {
            return array_values(array_map(
                static fn (mixed $connector): InstalledConnector => InstalledConnector::fromArray((array) $connector),
                $data['connectors'],
            ));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The published console UI bundles the cache recorded, by Solution id.
     *
     * @return array<string, SolutionUiBundle>|null null when there is no usable, fresh cache
     */
    public function bundles(): ?array
    {
        $data = $this->load();

        // A cache written before Core published bundles says nothing about them.
        if (null === $data || !is_array($data['bundles'] ?? null)) {
            return null;
        }

        try {
            $bundles = [];

            foreach ($data['bundles'] as $bundle) {
                $bundle                       = SolutionUiBundle::fromArray((array) $bundle);
                $bundles[$bundle->solutionId] = $bundle;
            }

            return $bundles;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  ConnectorDiscovery|null  $connectors  the connectors to record; null writes a cache that says nothing about them
     * @param  list<SolutionUiBundle>  $bundles  the published console UI bundles
     * @param  list<string>  $watchedFiles  more files whose change makes the cache stale: the `fapost-ui.json` of each
     *                                      Solution, so a new build is not taken for the published one
     */
    public function write(SolutionDiscovery $discovery, array $bundles = [], array $watchedFiles = [], ?ConnectorDiscovery $connectors = null): void
    {
        $files = [$discovery->installedJsonPath => $this->stamp($discovery->installedJsonPath)];

        foreach ([...$discovery->watchedFiles, ...($connectors->watchedFiles ?? []), ...$watchedFiles] as $file) {
            $files[$file] = $this->stamp($file);
        }

        $data = [
            'files'     => $files,
            'solutions' => array_map(static fn (InstalledSolution $solution): array => $solution->toArray(), $discovery->valid),
            'bundles'   => array_map(static fn (SolutionUiBundle $bundle): array => $bundle->toArray(), $bundles),
        ];

        if (null !== $connectors) {
            $data['connectors'] = array_map(static fn (InstalledConnector $connector): array => $connector->toArray(), $connectors->valid);
        }

        $contents = '<?php return ' . var_export($data, true) . ";\n";

        $temporary = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (false === @file_put_contents($temporary, $contents) || !@rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException(sprintf('Cannot write the Solution cache to %s.', $this->path));
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }

    public function forget(): bool
    {
        return !is_file($this->path) || @unlink($this->path);
    }

    /**
     * The cached data when the file is there, readable and fresh.
     *
     * @return array{files: array<string, mixed>, solutions: array<mixed>, bundles?: array<mixed>, connectors?: array<mixed>}|null
     */
    private function load(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        try {
            $data = (static fn (string $path): mixed => require $path)($this->path);

            if (!is_array($data) || !is_array($data['files'] ?? null) || !is_array($data['solutions'] ?? null)) {
                return null;
            }

            foreach ($data['files'] as $file => $stamp) {
                if (!is_string($file) || $stamp !== $this->stamp($file)) {
                    return null;
                }
            }

            /** @var array{files: array<string, mixed>, solutions: array<mixed>, bundles?: array<mixed>, connectors?: array<mixed>} $data */
            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{int, int}|null
     */
    private function stamp(string $file): ?array
    {
        clearstatcache(true, $file);

        if (!is_file($file)) {
            return null;
        }

        $mtime = filemtime($file);
        $size  = filesize($file);

        return false === $mtime || false === $size ? null : [$mtime, $size];
    }
}
