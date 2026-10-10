<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowActivityPeriod;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Reads one assistant's flow sessions for the operator console: the list with its filters, a session's details, its
 * flattened state and its audit history. Sessions are the engine's; nothing here writes.
 *
 * The console has no Filament tenancy scope, so every query is bounded by the current tenant and the assistant itself.
 */
final readonly class FlowSessionInspector
{
    /** The status filter value that lists every status; without a status filter the list shows the live ones. */
    public const string ALL_STATUSES = 'all';

    public const string LIVE_STATUSES = 'live';

    /** How many audit rows a session's page shows, newest first. */
    public const int HISTORY_LIMIT = 200;

    public function __construct(
        private TenantContextInterface $tenants,
    ) {
    }

    /**
     * A value as one line of text: strings as they are, other scalars and null as PHP literals, the rest as JSON.
     */
    public static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value) || null === $value) {
            return var_export($value, true);
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The assistant's sessions, narrowed by the status filter (`live` when none or an unknown one is asked for), with the
     * contact's external id as `contact_external_id` so the list can search and show it without loading contacts.
     *
     * @return Builder<FlowSession>
     */
    public function query(Assistant $assistant, ?string $status = null): Builder
    {
        $tenantId = $this->tenantId();

        $contacts = Contact::query()
            ->select(['contacts.id as contact_ref', 'contacts.external_id as contact_external_id'])
            ->where('contacts.tenant_id', $tenantId);

        $query = FlowSession::query()
            ->select(['flow_sessions.*', 'session_contacts.contact_external_id'])
            ->leftJoinSub($contacts, 'session_contacts', 'session_contacts.contact_ref', '=', 'flow_sessions.contact_id')
            ->where('flow_sessions.tenant_id', $tenantId)
            ->where('flow_sessions.assistant_id', (string) $assistant->getKey())
            ->with('flowDefinition:id,flow_id,name,logging_enabled');

        $statuses = $this->statuses($status);

        if (null !== $statuses) {
            $query->whereIn('flow_sessions.status', array_map(static fn (FlowSessionStatus $case): string => $case->value, $statuses));
        }

        return $query;
    }

    /**
     * Whether a status filter value is one the list understands; {@see query()} applies it.
     */
    public function acceptsStatus(string $value): bool
    {
        return self::ALL_STATUSES === $value || self::LIVE_STATUSES === $value || null !== FlowSessionStatus::tryFrom($value);
    }

    /**
     * Adds the contact's exact id to the list's search, when the text is one (the external id is matched by the table).
     *
     * @param  Builder<covariant Model>  $query  the search group
     */
    public function searchContactId(Builder $query, string $search): void
    {
        if (Str::isUuid($search)) {
            $query->orWhere('flow_sessions.contact_id', mb_strtolower($search));
        }
    }

    /**
     * Narrows the list to the sessions of one of the assistant's flows, whichever version they run.
     *
     * @param  Builder<covariant Model>  $query
     *
     * @return bool whether it narrowed the query (a flow that is not the assistant's is no filter)
     */
    public function filterByFlow(Builder $query, Assistant $assistant, string $flowId): bool
    {
        if (! Str::isUuid($flowId) || ! $this->flows($assistant)->where('flow_id', $flowId)->exists()) {
            return false;
        }

        $query->whereIn(
            'flow_sessions.flow_definition_id',
            FlowDefinition::query()->select('id')->where('tenant_id', $this->tenantId())->where('flow_id', $flowId),
        );

        return true;
    }

    /**
     * Narrows the list to the sessions started within a period.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function filterByPeriod(Builder $query, string $value, CarbonImmutable $now): bool
    {
        $period = FlowActivityPeriod::tryFrom($value);

        if (null === $period) {
            return false;
        }

        $query->where('flow_sessions.created_at', '>=', $period->since($now));

        return true;
    }

    /**
     * The assistant's flows, for the flow filter.
     *
     * @return list<array{value: string, label: string}>
     */
    public function flowOptions(Assistant $assistant): array
    {
        return $this->flows($assistant)
            ->orderBy('name')
            ->get(['flow_id', 'name'])
            ->map(static fn (FlowDraft $flow): array => ['value' => (string) $flow->flow_id, 'label' => (string) $flow->name])
            ->values()
            ->all();
    }

    /**
     * @throws ModelNotFoundException when the id is not a session of the assistant
     */
    public function findFor(Assistant $assistant, string $id): FlowSession
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(FlowSession::class, [$id]);
        }

        return $this->query($assistant, self::ALL_STATUSES)->where('flow_sessions.id', $id)->firstOrFail();
    }

    /**
     * Whether the session has an audit trail to show: its flow logs history, or rows were written anyway (the flag can
     * change while a session runs).
     */
    public function hasHistory(FlowSession $session): bool
    {
        if ((bool) ($session->flowDefinition?->logging_enabled ?? false)) {
            return true;
        }

        return $session->historyEntries()->exists();
    }

    /**
     * The newest {@see self::HISTORY_LIMIT} audit rows of a session.
     *
     * @return list<array{id: string, createdAt: string|null, nodeId: string, event: string, path: string|null, payload: string|null}>
     */
    public function history(FlowSession $session): array
    {
        return $session->historyEntries()
            ->where('tenant_id', $this->tenantId())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (FlowSessionHistoryEntry $entry): array => [
                'id'        => (string) $entry->getKey(),
                'createdAt' => $entry->created_at?->toIso8601String(),
                'nodeId'    => (string) $entry->node_id,
                'event'     => $entry->event_type instanceof BackedEnum ? (string) $entry->event_type->value : (string) $entry->event_type,
                'path'      => null === $entry->path || '' === $entry->path ? null : (string) $entry->path,
                'payload'   => $this->payload($entry),
            ])
            ->values()
            ->all();
    }

    /**
     * The namespaced session state flattened to `namespace.path → value` (a list stays one value), as a list so the
     * order survives JSON and the browser.
     *
     * @param  array<array-key, mixed>  $state
     *
     * @return list<array{key: string, value: string}>
     */
    public function flattenState(array $state, string $prefix = ''): array
    {
        $flat = [];

        foreach ($state as $key => $value) {
            $path = '' === $prefix ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && [] !== $value && ! array_is_list($value)) {
                $flat = [...$flat, ...$this->flattenState($value, $path)];

                continue;
            }

            $flat[] = ['key' => $path, 'value' => self::stringify($value)];
        }

        return $flat;
    }

    /**
     * @return list<FlowSessionStatus>|null null for every status
     */
    private function statuses(?string $status): ?array
    {
        if (self::ALL_STATUSES === $status) {
            return null;
        }

        $single = null === $status ? null : FlowSessionStatus::tryFrom($status);

        return null === $single ? FlowSessionStatus::live() : [$single];
    }

    /**
     * @return Builder<FlowDraft>
     */
    private function flows(Assistant $assistant): Builder
    {
        return FlowDraft::query()
            ->where('tenant_id', $this->tenantId())
            ->where('assistant_id', (string) $assistant->getKey());
    }

    private function payload(FlowSessionHistoryEntry $entry): ?string
    {
        $parts = [];

        if (null !== $entry->old_value) {
            $parts[] = 'old: ' . self::stringify($entry->old_value);
        }

        if (null !== $entry->new_value) {
            $parts[] = 'new: ' . self::stringify($entry->new_value);
        }

        if (is_array($entry->metadata) && [] !== $entry->metadata) {
            $parts[] = 'meta: ' . self::stringify($entry->metadata);
        }

        return [] === $parts ? null : implode(' · ', $parts);
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
