<?php

declare(strict_types=1);

namespace App\Domains\Solution\Infrastructure;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\DTO\SolutionActivation;
use App\Domains\Solution\DTO\SolutionActivationEvent;
use App\Domains\Solution\Enums\ActivationAction;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use Illuminate\Support\Facades\DB;

/**
 * Keeps activation in the tenant schema the caller is switched into (the default connection).
 *
 * A change locks the Solution's row and writes the row and its journal entry in one transaction. The
 * first activation inserts the row with `insertOrIgnore` and reads it back under the lock, so two
 * concurrent first activations meet on the unique `solution_id` and only one of them journals.
 */
final class EloquentSolutionActivationStore implements SolutionActivationStoreInterface
{
    public function activeIds(): array
    {
        /** @var list<string> $ids */
        $ids = SolutionActivationRecord::query()
            ->where('status', SolutionActivationStatus::Active->value)
            ->orderBy('solution_id')
            ->pluck('solution_id')
            ->all();

        return $ids;
    }

    public function all(): array
    {
        return SolutionActivationRecord::query()
            ->orderBy('solution_id')
            ->get()
            ->map(static fn (SolutionActivationRecord $row): SolutionActivation => new SolutionActivation(
                $row->solution_id,
                $row->status,
                $row->activated_at,
                $row->deactivated_at,
            ))
            ->values()
            ->all();
    }

    public function events(string $solutionId): array
    {
        return SolutionActivationEventRecord::query()
            ->where('solution_id', $solutionId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (SolutionActivationEventRecord $row): SolutionActivationEvent => new SolutionActivationEvent(
                $row->solution_id,
                $row->action,
                $row->actor_kind,
                $row->actor_id,
                $row->actor_label,
                $row->reason,
                $row->occurred_at,
            ))
            ->values()
            ->all();
    }

    public function change(string $solutionId, SolutionActivationStatus $target, ActivationActor $actor, ?string $reason): ActivationChange
    {
        return DB::transaction(function () use ($solutionId, $target, $actor, $reason): ActivationChange {
            $now = now();

            if (SolutionActivationStatus::Active === $target) {
                SolutionActivationRecord::query()->insertOrIgnore([
                    'id'           => (new SolutionActivationRecord())->newUniqueId(),
                    'solution_id'  => $solutionId,
                    'status'       => SolutionActivationStatus::Inactive->value,
                    'activated_at' => $now,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }

            $row = SolutionActivationRecord::query()->where('solution_id', $solutionId)->lockForUpdate()->first();

            if (null === $row || $row->status === $target) {
                return ActivationChange::Unchanged;
            }

            if (SolutionActivationStatus::Active === $target) {
                $row->activated_at = $now;
            } else {
                $row->deactivated_at = $now;
            }

            $row->status = $target;
            $row->save();

            SolutionActivationEventRecord::query()->create([
                'solution_id' => $solutionId,
                'action'      => SolutionActivationStatus::Active === $target ? ActivationAction::Activated : ActivationAction::Deactivated,
                'actor_kind'  => $actor->kind,
                'actor_id'    => $actor->id,
                'actor_label' => $actor->label,
                'reason'      => $reason,
                'occurred_at' => $now,
            ]);

            return SolutionActivationStatus::Active === $target ? ActivationChange::Activated : ActivationChange::Deactivated;
        });
    }
}
