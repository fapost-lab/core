<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Retention;

use Carbon\CarbonImmutable;

/**
 * Decides which monthly conversation_messages partitions have aged out.
 *
 * A partition is dropped only when its whole month lies at or before the
 * cutoff (`now - retentionDays`); the month containing the cutoff and the
 * current month are always kept. Messages therefore live between
 * `retentionDays` and roughly `retentionDays + 31` days.
 */
final readonly class PartitionRetention
{
    public function __construct(
        private int $retentionDays,
    ) {
    }

    /**
     * Strict parser for CONVERSATION_RETENTION_DAYS: only a plain positive
     * whole number (int or digit string) enables pruning; anything else
     * (`12m`, `true`, `0`, `-5`, empty, unset) yields null = keep forever.
     */
    public static function parseDays(mixed $raw): ?int
    {
        if (! is_int($raw) && ! is_string($raw)) {
            return null;
        }

        $days = filter_var(is_string($raw) ? mb_trim($raw) : $raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return false === $days ? null : $days;
    }

    public function isExpired(string $partitionName, CarbonImmutable $now): bool
    {
        $month = $this->monthOf($partitionName);

        if (null === $month) {
            return false;
        }

        $cutoff = $now->utc()->subDays($this->retentionDays);

        return $month->addMonth()->lessThanOrEqualTo($cutoff);
    }

    /**
     * @param list<string> $partitionNames
     *
     * @return list<string>
     */
    public function expired(array $partitionNames, CarbonImmutable $now): array
    {
        return array_values(array_filter(
            $partitionNames,
            fn (string $name): bool => $this->isExpired($name, $now),
        ));
    }

    private function monthOf(string $partitionName): ?CarbonImmutable
    {
        if (1 !== preg_match('/^conversation_messages_(\d{4}_\d{2})$/', $partitionName, $matches)) {
            return null;
        }

        $month = CarbonImmutable::createFromFormat('!Y_m', $matches[1], 'UTC');

        return false === $month ? null : $month->startOfMonth();
    }
}
