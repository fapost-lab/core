<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Models\BroadcastRecipient;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a broadcast did, for the operator: its outcomes by recipient status, its recipients one by one, and the latest
 * runs for the admin dashboard. It only reads.
 *
 * The outcomes are counted over `broadcast_recipients` (one grouped count on the `(broadcast_id, status)` index), not
 * taken from the broadcast's counters: the recipient rows are what the drill-down lists, so the totals and the list
 * always agree, and a pending recipient has no counter of its own.
 *
 * Every read is bounded by the tenant explicitly: no global scope does it implicitly. Finding the broadcast itself, and
 * bounding it by the current assistant, is {@see BroadcastService::findForAssistant()}'s.
 */
final readonly class BroadcastReport
{
    /**
     * How many runs the admin dashboard lists.
     */
    public const int LATEST = 5;

    public function __construct(
        private TenantContextInterface $tenants,
    ) {
    }

    /**
     * The broadcast's recipients by outcome. `pending` is the recipients not settled yet; `total` is all of them, which
     * is 0 for a draft and while a run is still writing its audience. A deleted contact takes its recipient row with it,
     * so after contacts are deleted these figures are lower than the broadcast's own counters, which the list shows.
     *
     * @return array{pending: int, sent: int, failed: int, skipped: int, total: int}
     */
    public function outcomes(Broadcast $broadcast): array
    {
        /** @var array<string, int|string> $counts */
        $counts = $this->recipientRows($broadcast)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $count = static fn (RecipientStatus $status): int => (int) ($counts[$status->value] ?? 0);

        $outcomes = [
            'pending' => $count(RecipientStatus::Pending),
            'sent'    => $count(RecipientStatus::Sent),
            'failed'  => $count(RecipientStatus::Failed),
            'skipped' => $count(RecipientStatus::Skipped),
        ];

        return [...$outcomes, 'total' => array_sum($outcomes)];
    }

    /**
     * The broadcast's recipients, with the contact each was for: for the drill-down's table, which sorts and pages it.
     *
     * @return Builder<BroadcastRecipient>
     */
    public function recipients(Broadcast $broadcast): Builder
    {
        return $this->recipientRows($broadcast)->with('contact');
    }

    /**
     * Narrows the recipients to one status; false (no filter) for a value that is not one.
     *
     * @param  Builder<BroadcastRecipient>  $recipients
     */
    public function filterByStatus(Builder $recipients, string $value): bool
    {
        $status = RecipientStatus::tryFrom($value);

        if (null === $status) {
            return false;
        }

        $recipients->where('broadcast_recipients.status', $status->value);

        return true;
    }

    /**
     * The tenant's most recently started runs (a draft is not one), newest first, with their assistant, for the admin
     * dashboard.
     *
     * @param  Builder<Assistant>|null  $assistants  the assistants to list for; `null` is the whole tenant
     *
     * @return Collection<int, Broadcast>
     */
    public function latest(?Builder $assistants = null, int $limit = self::LATEST): Collection
    {
        $query = Broadcast::query()
            ->with('assistant')
            ->where('broadcasts.tenant_id', $this->tenantId())
            ->where('broadcasts.status', '!=', BroadcastStatus::Draft->value);

        if (null !== $assistants) {
            $query->whereIn('broadcasts.assistant_id', (clone $assistants)->select('assistants.id'));
        }

        return $query
            // When it started, not when it was written: a draft sent today is today's run. Every run has a start time;
            // the creation time only keeps the order total should one be missing.
            ->orderByRaw('COALESCE(broadcasts.started_at, broadcasts.created_at) DESC')
            ->orderByDesc('broadcasts.id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Builder<BroadcastRecipient>
     */
    private function recipientRows(Broadcast $broadcast): Builder
    {
        return BroadcastRecipient::query()
            ->where('broadcast_recipients.tenant_id', $this->tenantId())
            ->where('broadcast_recipients.broadcast_id', (string) $broadcast->getKey());
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
