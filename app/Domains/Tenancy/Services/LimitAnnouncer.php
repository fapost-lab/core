<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Events\LimitReached;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * The one place that raises {@see LimitReached}, called by the three classes every limit decision goes
 * through (`RecordQuota`, `PeriodQuota`, `MediaStorageGate`).
 *
 * Telling people about a limit must never change the decision, so a failing listener is reported
 * and swallowed here.
 */
final readonly class LimitAnnouncer
{
    public function __construct(
        private Dispatcher $events,
        private TenantContextInterface $context,
    ) {
    }

    public function refused(
        string $key,
        LimitKind $kind,
        int $limit,
        ?int $used,
        RefusedWork $refused,
        ?DateTimeImmutable $periodEndsAt = null,
    ): void {
        $this->announce($key, $kind, $limit, $used, $refused, $periodEndsAt);
    }

    /**
     * A record limit that was just filled: the saved record took the last place.
     */
    public function filled(string $key, int $limit): void
    {
        $this->announce($key, LimitKind::Records, $limit, $limit, null, null);
    }

    private function announce(
        string $key,
        LimitKind $kind,
        int $limit,
        ?int $used,
        ?RefusedWork $refused,
        ?DateTimeImmutable $periodEndsAt,
    ): void {
        try {
            $this->events->dispatch(new LimitReached(
                $this->context->get()->getId(),
                $key,
                $kind,
                $limit,
                $used,
                $refused,
                CarbonImmutable::now(),
                $periodEndsAt,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
