<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Contact\Models\LimitRefusal;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * What the active-contact limit turned away lately, for an assistant's dashboard.
 */
final readonly class LimitRefusalReport
{
    public const int DAYS = 30;

    public function __construct(
        private TenantLimitsInterface $limits,
    ) {
    }

    /**
     * Refusals over the last {@see DAYS} days at the given channels, or null when there were none.
     *
     * `limit` is the limit now, null when it was lifted since or, with `limitKnown` false, when the operator could not be asked.
     *
     * @param  list<string>  $channelIds
     *
     * @return array{people: int, messages: int, lastRefusedAt: string, limit: int|null, limitKnown: bool}|null
     */
    public function monthlyActiveContacts(string $tenantId, array $channelIds, CarbonImmutable $now): ?array
    {
        if ([] === $channelIds) {
            return null;
        }

        // Today and the 29 days before it: 30 calendar days.
        $since = $now->setTimezone('UTC')->subDays(self::DAYS - 1)->toDateString();
        $query = static fn (): Builder => LimitRefusal::query()
            ->where('limit_key', InboundContactGate::LIMIT_KEY)
            ->whereIn('channel_id', $channelIds)
            ->where('refused_on', '>=', $since);

        $messages = (int) $query()->sum('attempts');

        if (0 === $messages) {
            return null;
        }

        [$limit, $limitKnown] = $this->currentLimit($tenantId);

        return [
            'people'        => (int) $query()->distinct()->count('subject_hash'),
            'messages'      => $messages,
            'lastRefusedAt' => CarbonImmutable::parse((string) $query()->max('last_refused_at'), 'UTC')->toIso8601String(),
            'limit'         => $limit,
            'limitKnown'    => $limitKnown,
        ];
    }

    /**
     * The limit now. An operator that cannot answer is reported and the card goes without a number
     * rather than failing the page.
     *
     * @return array{0: int|null, 1: bool} the limit and whether it is known
     */
    private function currentLimit(string $tenantId): array
    {
        try {
            return [$this->limits->limitFor($tenantId, InboundContactGate::LIMIT_KEY), true];
        } catch (Throwable $exception) {
            report($exception);

            return [null, false];
        }
    }
}
