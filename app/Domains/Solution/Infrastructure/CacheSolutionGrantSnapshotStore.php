<?php

declare(strict_types=1);

namespace App\Domains\Solution\Infrastructure;

use App\Domains\Solution\Contracts\SolutionGrantSnapshotStoreInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\Enums\SolutionGrantSource;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Keeps the last grants in the application's cache store, which every process shares (Redis in production), under
 * `fapost:solution-grants:<tenant id>`. Plain arrays, so a payload written by another release reads as nothing rather
 * than as a broken object; an entry that does not read back is ignored.
 */
final readonly class CacheSolutionGrantSnapshotStore implements SolutionGrantSnapshotStoreInterface
{
    private const string PREFIX = 'fapost:solution-grants:';

    public function __construct(
        private Repository $cache,
    ) {
    }

    public function put(string $tenantId, array $grants, int $ttlSeconds): void
    {
        $this->cache->put(self::PREFIX . $tenantId, array_map(static fn (SolutionGrant $grant): array => [
            'id'      => $grant->solutionId,
            'source'  => $grant->source->value,
            'ends_at' => $grant->endsAt?->format(DateTimeInterface::ATOM),
            'note'    => $grant->note,
        ], $grants), $ttlSeconds);
    }

    public function get(string $tenantId): array
    {
        $rows = $this->cache->get(self::PREFIX . $tenantId);

        if (! is_array($rows)) {
            return [];
        }

        $grants = [];

        foreach ($rows as $row) {
            try {
                $source = is_array($row) && is_string($row['id'] ?? null) && is_string($row['source'] ?? null) ? SolutionGrantSource::tryFrom($row['source']) : null;

                if (null === $source) {
                    continue;
                }

                $endsAt   = is_string($row['ends_at'] ?? null) ? new DateTimeImmutable($row['ends_at']) : null;
                $note     = is_string($row['note'] ?? null) ? $row['note'] : null;
                $grants[] = new SolutionGrant($row['id'], $source, $endsAt, $note);
            } catch (Throwable) {
                continue;
            }
        }

        return $grants;
    }

    public function forget(string $tenantId): void
    {
        $this->cache->forget(self::PREFIX . $tenantId);
    }
}
