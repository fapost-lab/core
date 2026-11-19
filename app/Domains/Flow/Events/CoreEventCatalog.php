<?php

declare(strict_types=1);

namespace App\Domains\Flow\Events;

/**
 * The events Core itself raises for event triggers, and the payload each one carries under `flow.event.*`.
 *
 * A Core event starts a contactless automation run on every active event trigger of the tenant that names it
 * ({@see \App\Jobs\Flow\DispatchFlowTriggerEventJob}); the run gets no contact even when the event is about one — the
 * contact's id is in the payload. Payloads carry identifiers and short fields only, never a contact's personal data
 * (name, phone): the whole payload is kept in the run's state and history.
 *
 * Every payload also carries `event_id` (the fire key of the runs the event starts) and `occurred_at` (ISO 8601).
 *
 * The prefix {@see RESERVED_PREFIX} belongs to Core: a flow's `emit_event` may not use it, so no flow can fake a Core
 * event with a payload of its own. The names are not written to `tenant_events`; the trigger picker lists them from
 * here ({@see \App\Domains\Flow\Repositories\TenantEventRepository}).
 */
final class CoreEventCatalog
{
    public const string RESERVED_PREFIX = 'core.';

    public const string CONTACT_CREATED = 'core.contact.created';

    public const string CONTACT_TAG_ADDED = 'core.contact.tag_added';

    public const string CONTACT_TAG_REMOVED = 'core.contact.tag_removed';

    public const string BROADCAST_COMPLETED = 'core.broadcast.completed';

    public const string OPERATOR_REQUEST_TIMED_OUT = 'core.operator_request.timed_out';

    /**
     * The payload fields of each event, besides `event_id` and `occurred_at`.
     *
     * @var array<string, list<string>>
     */
    private const array FIELDS = [
        self::CONTACT_CREATED            => ['contact_id', 'platform', 'language'],
        self::CONTACT_TAG_ADDED          => ['contact_id', 'tag', 'tagged_by'],
        self::CONTACT_TAG_REMOVED        => ['contact_id', 'tag'],
        self::BROADCAST_COMPLETED        => ['broadcast_id', 'name', 'recipients_total', 'sent', 'failed'],
        self::OPERATOR_REQUEST_TIMED_OUT => ['request_id', 'contact_id', 'assistant_id'],
    ];

    /**
     * @return list<string> the Core event names, in catalog order
     */
    public static function names(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function has(string $eventName): bool
    {
        return array_key_exists($eventName, self::FIELDS);
    }

    /**
     * @return list<string> the payload fields of a Core event besides `event_id` and `occurred_at`; empty for a name
     *                      outside the catalog
     */
    public static function fields(string $eventName): array
    {
        return self::FIELDS[$eventName] ?? [];
    }

    /**
     * Whether a name is in Core's reserved namespace, whether or not the catalog has it yet.
     */
    public static function isReserved(string $eventName): bool
    {
        return str_starts_with(mb_strtolower(mb_trim($eventName)), self::RESERVED_PREFIX);
    }
}
