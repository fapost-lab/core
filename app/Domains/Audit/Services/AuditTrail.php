<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Contracts\AuditActorResolverInterface;
use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Exceptions\AuditEntryNotRecordedException;
use App\Domains\Audit\Models\ActivityEntry;
use Illuminate\Contracts\Config\Repository;
use Spatie\Activitylog\Support\ActivityLogger;
use Spatie\Activitylog\Support\ActivityLogStatus;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Writes audit entries through the package's {@see ActivityLogger}: a plain insert on the current connection, so it
 * lands in the current tenant's schema and in the caller's transaction.
 *
 * A logger is built per entry: it keeps the entry it is building between calls, and an insert that failed would leave
 * it for the next one. The causer is always set explicitly (the actor, or anonymous for the system), never left to the
 * package's guess from the auth guard. The keys in `$neverRecorded` are dropped from the values and properties of every
 * entry, a safety net behind each action's whitelist.
 */
final readonly class AuditTrail implements AuditTrailInterface
{
    /**
     * @param  list<string>  $neverRecorded
     */
    public function __construct(
        private Repository $config,
        private ActivityLogStatus $status,
        private CauserResolver $causers,
        private AuditActorResolverInterface $actors,
        private array $neverRecorded,
    ) {
    }

    public function record(AuditRecord $record): void
    {
        $actor   = $record->actor ?? $this->actors->current();
        $changes = $record->changes?->toArray() ?? [];

        foreach (['attributes', 'old'] as $part) {
            if (isset($changes[$part])) {
                $changes[$part] = $this->scrub($changes[$part]);
            }
        }

        $logger = new ActivityLogger($this->config, $this->status, $this->causers);

        $logger
            ->useLog($record->action->area()->value)
            ->event($record->action->event())
            ->withChanges($changes)
            ->withProperties($this->scrub([...$actor->properties, ...$record->properties]));

        if (null !== $record->subject) {
            $logger->performedOn($record->subject);
        }

        if (null !== $actor->causer) {
            $logger->causedBy($actor->causer);
        } else {
            $logger->causedByAnonymous();
        }

        $logger->tap(static function (ActivityEntry $entry) use ($actor, $record): void {
            $entry->actor_kind    = $actor->kind;
            $entry->actor_label   = $actor->label;
            $entry->subject_label = $record->subjectLabel;
            $entry->assistant_id  = $record->assistantId;
        });

        if (null === $logger->log($record->action->value)) {
            throw AuditEntryNotRecordedException::loggingDisabled($record->action->value);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @return array<string, mixed>
     */
    private function scrub(array $values): array
    {
        return array_diff_key($values, array_flip($this->neverRecorded));
    }
}
