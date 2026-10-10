<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Enums\FlowActivityPeriod;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

/**
 * Reads one assistant's flow log for the operator console. Nothing here writes: `FlowLogWriter` owns the table.
 *
 * Every query is bounded by `created_at`. `flow_logs` is range-partitioned by month with the primary key
 * `(id, created_at)`, so without that bound a query — and the page count of a list — reads every partition:
 *  - the list looks back over a {@see FlowActivityPeriod};
 *  - one entry is found by its id within {@see self::ID_TIME_TOLERANCE_SECONDS} of the time its ULID carries
 *    (`FlowLogWriter` makes the id and `created_at` in the same instant), which reaches a single partition.
 *
 * `flow_logs` has neither a tenant nor an assistant column: entries are the assistant's through their session.
 */
final readonly class FlowLogInspector
{
    public const int ID_TIME_TOLERANCE_SECONDS = 300;

    /** The value of the errors filter that keeps only the entries with an error. */
    public const string ERRORS_ONLY = '1';

    public function __construct(
        private TenantContextInterface $tenants,
        private NodeHandlerRegistryInterface $handlers,
    ) {
    }

    /**
     * The assistant's entries written since the start of the period.
     *
     * @return Builder<FlowLog>
     */
    public function query(Assistant $assistant, FlowActivityPeriod $period, CarbonImmutable $now): Builder
    {
        return $this->scoped($assistant)->where('flow_logs.created_at', '>=', $period->since($now));
    }

    /**
     * @param  Builder<covariant Model>  $query
     *
     * @return bool whether it narrowed the query (a value that is no session id is no filter)
     */
    public function filterBySession(Builder $query, string $value): bool
    {
        if (! Str::isUuid($value)) {
            return false;
        }

        $query->where('flow_logs.session_id', $value);

        return true;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function filterByNodeType(Builder $query, string $value): bool
    {
        if (! in_array($value, $this->nodeTypes(), true)) {
            return false;
        }

        $query->where('flow_logs.node_type', $value);

        return true;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function filterByStatus(Builder $query, string $value): bool
    {
        $status = FlowLogStatus::tryFrom($value);

        if (null === $status) {
            return false;
        }

        $query->where('flow_logs.status', $status->value);

        return true;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function filterByErrors(Builder $query, string $value): bool
    {
        if (self::ERRORS_ONLY !== $value) {
            return false;
        }

        $query->whereNotNull('flow_logs.error');

        return true;
    }

    /**
     * The node types the registry knows, for the node type filter.
     *
     * @return list<string>
     */
    public function nodeTypes(): array
    {
        $types = [];

        foreach ($this->handlers->all() as $handler) {
            $types[] = $handler->type();
        }

        $types = array_values(array_unique($types));
        sort($types);

        return $types;
    }

    /**
     * @throws ModelNotFoundException when the id is not an entry of the assistant written when its id says
     */
    public function findFor(Assistant $assistant, string $id): FlowLog
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(FlowLog::class, [$id]);
        }

        $writtenAt = CarbonImmutable::instance(Ulid::fromString($id)->getDateTime());

        return $this->scoped($assistant)
            ->where('flow_logs.id', $id)
            ->whereBetween('flow_logs.created_at', [
                $writtenAt->subSeconds(self::ID_TIME_TOLERANCE_SECONDS),
                $writtenAt->addSeconds(self::ID_TIME_TOLERANCE_SECONDS),
            ])
            ->firstOrFail();
    }

    /**
     * @return Builder<FlowLog>
     */
    private function scoped(Assistant $assistant): Builder
    {
        return FlowLog::query()
            ->select('flow_logs.*')
            ->whereIn(
                'flow_logs.session_id',
                FlowSession::query()
                    ->select('id')
                    ->where('tenant_id', $this->tenants->get()->getId())
                    ->where('assistant_id', (string) $assistant->getKey()),
            );
    }
}
