<?php

declare(strict_types=1);

namespace App\Domains\Audit\DTOs;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * What an action changed, in the form an entry stores (`attribute_changes`): new values under `attributes`, the values
 * they replaced under `old`, and the fields that changed without their values under `changed`.
 *
 * Every field is named by the caller, per action (a whitelist): a field of the value class keeps old and new (a name, a
 * status, a flag, a language); a field of the marker class is recorded by name alone (a message text, a channel's
 * config, a flow graph, a password). A secret (a token, a webhook hash, a password hash) is never named at all.
 */
final readonly class AuditChanges
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $old
     * @param  list<string>  $changed
     */
    private function __construct(
        public array $attributes = [],
        public array $old = [],
        public array $changed = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * The values a new record was created with.
     *
     * @param  array<string, mixed>  $values  only whitelisted fields
     */
    public static function created(array $values): self
    {
        return new self(self::normalizeAll($values));
    }

    /**
     * Fields that changed, by name only.
     *
     * @param  list<string>  $fields
     */
    public static function markers(array $fields): self
    {
        return new self([], [], array_values(array_unique($fields)));
    }

    /**
     * Compares two snapshots field by field: a changed `$values` field keeps both values, a changed `$markers` field is
     * recorded by name. Other keys of the snapshots are ignored, so a snapshot may hold more than is recorded.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $values
     * @param  list<string>  $markers
     */
    public static function between(array $before, array $after, array $values, array $markers = []): self
    {
        $attributes = [];
        $old        = [];
        $changed    = [];

        foreach ($values as $field) {
            $was = self::normalize($before[$field] ?? null);
            $now = self::normalize($after[$field] ?? null);

            if (self::differs($was, $now)) {
                $attributes[$field] = $now;
                $old[$field]        = $was;
            }
        }

        foreach ($markers as $field) {
            if (self::differs(self::normalize($before[$field] ?? null), self::normalize($after[$field] ?? null))) {
                $changed[] = $field;
            }
        }

        return new self($attributes, $old, $changed);
    }

    /**
     * What the last `save()` of the model changed, through its casts.
     *
     * @param  list<string>  $values
     * @param  list<string>  $markers
     */
    public static function ofSavedModel(Model $model, array $values, array $markers = []): self
    {
        $dirty = array_keys($model->getChanges());

        if ([] === $dirty) {
            return self::none();
        }

        $previous = $model->newInstance()->setRawAttributes($model->getPrevious());
        $before   = [];
        $after    = [];

        foreach ([...$values, ...$markers] as $field) {
            if (in_array($field, $dirty, true)) {
                $before[$field] = $previous->getAttribute($field);
                $after[$field]  = $model->getAttribute($field);
            }
        }

        return self::between($before, $after, $values, $markers);
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->attributes, ...$other->attributes],
            [...$this->old, ...$other->old],
            array_values(array_unique([...$this->changed, ...$other->changed])),
        );
    }

    public function isEmpty(): bool
    {
        return [] === $this->attributes && [] === $this->old && [] === $this->changed;
    }

    /**
     * @return array{attributes?: array<string, mixed>, old?: array<string, mixed>, changed?: list<string>}
     */
    public function toArray(): array
    {
        return array_filter([
            'attributes' => $this->attributes,
            'old'        => $this->old,
            'changed'    => $this->changed,
        ], static fn (array $part): bool => [] !== $part);
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @return array<string, mixed>
     */
    private static function normalizeAll(array $values): array
    {
        return array_map(self::normalize(...), $values);
    }

    /**
     * A value as JSON stores it: enums by their value, dates as ISO strings, collections as arrays.
     */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof JsonSerializable || $value instanceof Arrayable) {
            return json_decode((string) json_encode($value), true);
        }

        return $value;
    }

    private static function differs(mixed $was, mixed $now): bool
    {
        return json_encode($was) !== json_encode($now);
    }
}
