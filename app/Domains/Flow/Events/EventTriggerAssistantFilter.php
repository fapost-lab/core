<?php

declare(strict_types=1);

namespace App\Domains\Flow\Events;

/**
 * The `assistant_filter` of an event trigger: the assistants whose flows' events start it.
 *
 * It matches the event's **source** — the assistant of the session whose `emit_event` raised it — not the trigger's
 * own assistant, which only says on whose behalf the started flow runs. Empty (or absent) means any assistant, the
 * behaviour of every trigger saved before the filter existed. A Core event has no source assistant, so validation
 * refuses a filter on a trigger that listens to one (`assistant_filter_on_core_event`).
 */
final readonly class EventTriggerAssistantFilter
{
    public const string CONFIG_KEY = 'assistant_filter';

    /**
     * @param  list<string>  $assistantIds
     */
    private function __construct(
        public array $assistantIds,
    ) {
    }

    /**
     * Reads the filter from a trigger's config; anything that is not a non-empty string is ignored.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $raw = $config[self::CONFIG_KEY] ?? null;

        if (! is_array($raw)) {
            return new self([]);
        }

        $ids = [];

        foreach ($raw as $value) {
            if (is_string($value) && '' !== mb_trim($value)) {
                $ids[] = mb_trim($value);
            }
        }

        return new self(array_values(array_unique($ids)));
    }

    public function isEmpty(): bool
    {
        return [] === $this->assistantIds;
    }

    /**
     * Whether an event raised by a session of this assistant starts the trigger. An unknown source assistant (`null`)
     * passes only an empty filter.
     */
    public function allows(?string $sourceAssistantId): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        if (null === $sourceAssistantId || '' === $sourceAssistantId) {
            return false;
        }

        return in_array(mb_strtolower($sourceAssistantId), array_map(mb_strtolower(...), $this->assistantIds), true);
    }
}
