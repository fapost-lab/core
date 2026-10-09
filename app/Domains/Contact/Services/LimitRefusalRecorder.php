<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Contact\Models\LimitRefusal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;

/**
 * Keeps the tenant-visible record of work a per-period limit turned away: one row per limit key,
 * person (hash) and UTC day, counting the refused messages. Runs inside a tenant switch.
 */
final class LimitRefusalRecorder
{
    public function record(string $limitKey, string $subjectHash, string $channelId, CarbonImmutable $now): void
    {
        $now = $now->setTimezone('UTC');

        LimitRefusal::query()->upsert(
            [[
                'id'              => mb_strtolower((string) Str::ulid()->toRfc4122()),
                'limit_key'       => $limitKey,
                'subject_hash'    => $subjectHash,
                'channel_id'      => $channelId,
                'refused_on'      => $now->toDateString(),
                'attempts'        => 1,
                'last_refused_at' => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]],
            ['limit_key', 'subject_hash', 'channel_id', 'refused_on'],
            [
                'attempts'        => new Expression('limit_refusals.attempts + 1'),
                'last_refused_at' => $now,
                'updated_at'      => $now,
            ],
        );
    }

    /**
     * Removes the refusals of days before the cutoff.
     *
     * @return int rows removed
     */
    public function pruneBefore(CarbonImmutable $cutoff): int
    {
        return LimitRefusal::query()->where('refused_on', '<', $cutoff->setTimezone('UTC')->toDateString())->delete();
    }
}
