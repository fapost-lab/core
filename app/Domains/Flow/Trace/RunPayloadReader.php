<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Reads the data a node visit carries in the trace ({@see NodeTraceRecorder}): its `input`, its `output` and its error
 * message — masked by a deny-list ({@see TracePayloadMasker}) and limited ({@see TracePayloadLimiter}), as written.
 *
 * The only code that reads them for a screen. Call it only after the `viewPayloads` ability of the session's run
 * (`FlowSessionPolicy::viewPayloads()`, the `ViewRunPayloads` permission): the status reader ({@see RunGraphReader})
 * never selects these fields, so a screen gated by `ViewFlowSessions` alone cannot pick them up. One visit is read at a
 * time, so a response is bounded by the limit of one trace row.
 *
 * Every query is bounded by the current tenant and by the session the caller found.
 */
final readonly class RunPayloadReader
{
    public function __construct(
        private TenantContextInterface $tenants,
    ) {
    }

    /**
     * The data of one trace row of the session, or null when the id is not one of the session's trace rows (another
     * session's, a history row that is not the trace, an unknown or malformed id).
     *
     * The row's envelope is decoded whole rather than selected by JSON path: a value the limiter replaced is the bare
     * string `"[truncated, N bytes]"`, which a JSON-path select returns unquoted and so not as JSON.
     *
     * @return array{input: mixed, output: mixed, errorMessage: string|null}|null
     */
    public function payload(FlowSession $session, string $entryId): ?array
    {
        if (! Str::isUuid($entryId)) {
            return null;
        }

        $entry = $this->traceQuery($session)->whereKey($entryId)->first(['id', 'metadata']);

        if (null === $entry) {
            return null;
        }

        $metadata = is_array($entry->metadata) ? $entry->metadata : [];
        $error    = is_array($metadata['error'] ?? null) ? $metadata['error'] : [];
        $message  = $error['message'] ?? null;

        return [
            'input'        => $metadata['input'] ?? null,
            'output'       => $metadata['output'] ?? null,
            'errorMessage' => is_string($message) && '' !== $message ? $message : null,
        ];
    }

    /**
     * Which of the given trace rows of the session carry data ({@see self::payload()}), without reading it: the keys are
     * checked in SQL. An empty error message is no data, as {@see self::payload()} reads it: a handler that throws an
     * exception without a message records one.
     *
     * @param  list<string>  $entryIds
     *
     * @return array<string, true> the ids that carry data
     */
    public function presence(FlowSession $session, array $entryIds): array
    {
        $entryIds = array_values(array_filter($entryIds, static fn (string $id): bool => Str::isUuid($id)));

        if ([] === $entryIds) {
            return [];
        }

        $ids = $this->traceQuery($session)
            ->whereIn('id', $entryIds)
            ->where(static fn (Builder $query): Builder => $query
                ->whereJsonContainsKey('metadata->input')
                ->orWhereJsonContainsKey('metadata->output')
                ->orWhere('metadata->error->message', '<>', ''))
            ->pluck('id');

        $out = [];

        foreach ($ids as $id) {
            $out[(string) $id] = true;
        }

        return $out;
    }

    /**
     * @return Builder<FlowSessionHistoryEntry>
     */
    private function traceQuery(FlowSession $session): Builder
    {
        return FlowSessionHistoryEntry::query()
            ->where('tenant_id', $this->tenants->get()->getId())
            ->where('session_id', (string) $session->getKey())
            ->whereIn('event_type', NodeTraceRecorder::EVENT_TYPES);
    }
}
