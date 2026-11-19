<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Exceptions\InvalidTriggerPayloadException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use Illuminate\Support\Str;

/**
 * A flow's list of triggers, as the draft and a published definition carry it: `{id, type, is_active, priority,
 * config}` per trigger, where `id` is the primary key of the trigger's live `flow_triggers` row.
 *
 * `null` on a draft or a definition means the list was never written there (a flow last saved before triggers were
 * carried): the flow's live rows are its triggers then. `[]` means the flow has none. Builder load, validate and
 * publish all read the list through {@see self::ofDraft()} and {@see self::ofDefinition()}.
 *
 * @phpstan-type Trigger array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}
 */
final readonly class FlowTriggerSet
{
    public function __construct(
        private FlowTriggerRepositoryInterface $triggers,
    ) {
    }

    /**
     * @return Trigger
     */
    public static function fromRow(FlowTrigger $row): array
    {
        return [
            'id'        => mb_strtolower($row->id),
            'type'      => $row->type->value,
            'is_active' => $row->is_active,
            'priority'  => $row->priority,
            'config'    => is_array($row->config) ? $row->config : [],
        ];
    }

    /**
     * Checks the shape a draft save needs and nothing deeper (autosave never validates): a list of objects, each with
     * a UUID `id`, a non-empty string `type`, an object `config`, and when given a boolean `is_active` and a
     * non-negative integer `priority`. Ids are lowercased, as Postgres returns a uuid;
     * `is_active` defaults to true and `priority` to 100.
     *
     * @return list<Trigger>
     *
     * @throws InvalidTriggerPayloadException
     */
    public static function fromPayload(mixed $payload): array
    {
        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new InvalidTriggerPayloadException('Triggers must be a list.');
        }

        $triggers = [];

        foreach ($payload as $index => $trigger) {
            if (! is_array($trigger)) {
                throw new InvalidTriggerPayloadException("Trigger #{$index} must be an object.");
            }

            $id = $trigger['id'] ?? null;

            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new InvalidTriggerPayloadException("Trigger #{$index} needs a UUID id.");
            }

            $type = $trigger['type'] ?? null;

            if (! is_string($type) || '' === $type) {
                throw new InvalidTriggerPayloadException('Trigger type is required.');
            }

            if (! is_array($trigger['config'] ?? null)) {
                throw new InvalidTriggerPayloadException('Trigger config must be an object.');
            }

            // A value of the wrong type is refused, not defaulted: publish checks the stored list and would never see it.
            if (array_key_exists('is_active', $trigger) && ! is_bool($trigger['is_active'])) {
                throw new InvalidTriggerPayloadException('Trigger active flag must be boolean.');
            }

            if (array_key_exists('priority', $trigger) && (! is_int($trigger['priority']) || $trigger['priority'] < 0)) {
                throw new InvalidTriggerPayloadException('Trigger priority must be a non-negative integer.');
            }
        }

        return self::normalize($payload);
    }

    /**
     * The stored list in its canonical shape. Entries that are not objects are dropped; a stored list went through
     * {@see self::fromPayload()}, so this only fills defaults.
     *
     * @param  array<array-key, mixed>  $stored
     *
     * @return list<Trigger>
     */
    public static function normalize(array $stored): array
    {
        $triggers = [];

        foreach ($stored as $trigger) {
            if (! is_array($trigger)) {
                continue;
            }

            $triggers[] = [
                'id'        => is_string($trigger['id'] ?? null) ? mb_strtolower($trigger['id']) : '',
                'type'      => is_string($trigger['type'] ?? null) ? $trigger['type'] : '',
                'is_active' => is_bool($trigger['is_active'] ?? null) ? $trigger['is_active'] : true,
                'priority'  => is_int($trigger['priority'] ?? null) ? $trigger['priority'] : 100,
                'config'    => is_array($trigger['config'] ?? null) ? $trigger['config'] : [],
            ];
        }

        return $triggers;
    }

    /**
     * Whether two trigger configs say the same, whatever the order of their keys: Postgres `jsonb` returns object
     * keys in its own order, so a config read back from a row never compares equal to the one written as is. The
     * order of a list is kept, it is part of the value.
     *
     * @param  array<array-key, mixed>  $left
     * @param  array<array-key, mixed>  $right
     */
    public static function sameConfig(array $left, array $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    /**
     * The draft's triggers, or the flow's live ones while the draft carries none.
     *
     * @return list<Trigger>
     */
    public function ofDraft(FlowDraft $draft): array
    {
        return is_array($draft->triggers) ? self::normalize($draft->triggers) : $this->live($draft->flow_id);
    }

    /**
     * The triggers a definition was published with, or the flow's live ones for a definition published before
     * triggers were carried.
     *
     * @return list<Trigger>
     */
    public function ofDefinition(FlowDefinition $definition): array
    {
        return is_array($definition->triggers) ? self::normalize($definition->triggers) : $this->live($definition->flow_id);
    }

    /**
     * @return list<Trigger>
     */
    public function live(string $flowId): array
    {
        return array_map(self::fromRow(...), $this->triggers->getByFlowId($flowId));
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
