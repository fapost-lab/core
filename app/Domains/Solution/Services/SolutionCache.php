<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionDiscovery;
use RuntimeException;
use Throwable;

/**
 * A compiled copy of the valid Solutions, so a request does not parse installed.json.
 *
 * Only an accelerator: the file is written by `platform:update` and `solutions:cache`, and when it is
 * missing, unreadable or stale it is ignored and the catalog is built in memory. It records the size
 * and modification time of installed.json and of the composer.json of every candidate package, valid or not; any difference makes
 * it stale. A request never writes it.
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

            return array_values(array_map(
                static fn (mixed $solution): InstalledSolution => InstalledSolution::fromArray((array) $solution),
                $data['solutions'],
            ));
        } catch (Throwable) {
            return null;
        }
    }

    public function write(SolutionDiscovery $discovery): void
    {
        $files = [$discovery->installedJsonPath => $this->stamp($discovery->installedJsonPath)];

        foreach ($discovery->watchedFiles as $file) {
            $files[$file] = $this->stamp($file);
        }

        $contents = '<?php return ' . var_export([
            'files'     => $files,
            'solutions' => array_map(static fn (InstalledSolution $solution): array => $solution->toArray(), $discovery->valid),
        ], true) . ";\n";

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
