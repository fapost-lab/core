<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest\Support;

use JsonException;
use RuntimeException;

/**
 * Persists the one piece of cross-command state the load-test harness needs:
 * which tenants/assistants/channels/contacts `loadtest:seed` created, so
 * `loadtest:run`, `loadtest:verify` and `loadtest:clean` all act on exactly
 * that set and nothing else.
 *
 * A single file under storage/app/loadtest/ — this harness supports one
 * active run at a time, matching how it's invoked (`make loadtest`).
 */
final class LoadTestStateStore
{
    private const string RELATIVE_PATH = 'app/loadtest/state.json';

    public function path(): string
    {
        return storage_path(self::RELATIVE_PATH);
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException  when no run has been seeded yet.
     */
    public function load(): array
    {
        if (! $this->exists()) {
            throw new RuntimeException(
                "No load-test state at {$this->path()}. Run `php artisan loadtest:seed` first.",
            );
        }

        $raw = file_get_contents($this->path());

        if (false === $raw) {
            throw new RuntimeException("Could not read load-test state at {$this->path()}.");
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Load-test state at {$this->path()} is corrupt: {$exception->getMessage()}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function save(array $state): void
    {
        $directory = dirname($this->path());

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            $this->path(),
            json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    public function clear(): void
    {
        if ($this->exists()) {
            unlink($this->path());
        }
    }
}
