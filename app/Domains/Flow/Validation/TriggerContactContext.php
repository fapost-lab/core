<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Events\CoreEventCatalog;

/**
 * Whether a run one trigger starts has a contact — decided by the trigger's type and, for an event trigger, by its
 * event: a flow's own event (`emit_event` in a conversation) starts the run for the emitting contact, a Core event
 * ({@see CoreEventCatalog}) starts a contactless run even when the event is about a contact. A type an extension
 * registered (given as its string) never gives one: its runs are contactless (`adr-20261123-solution-trigger-types`).
 */
final class TriggerContactContext
{
    /**
     * @param  array<array-key, mixed>  $config  the trigger's config
     */
    public static function givesContact(FlowTriggerType|string $type, array $config): bool
    {
        if (is_string($type)) {
            $type = FlowTriggerType::tryFrom($type);

            if (null === $type) {
                return false;
            }
        }

        if (FlowTriggerType::Event === $type) {
            $eventName = $config['event_name'] ?? null;

            return ! (is_string($eventName) && CoreEventCatalog::has($eventName));
        }

        return $type->givesContact();
    }
}
