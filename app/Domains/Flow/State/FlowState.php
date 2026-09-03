<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use App\Domains\Flow\State\Exceptions\InvalidStatePathException;

/**
 * Mutable typed container for session-backed runtime state.
 *
 * The engine intentionally mutates this object in place during node execution
 * to avoid repeated deep array copies in hot paths.
 */
final class FlowState
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private array $data = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(StatePath $path): mixed
    {
        $keys  = array_merge([$path->namespace->value], explode('.', $path->leaf));
        $value = $this->data;

        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    public function set(StatePath $path, mixed $value): void
    {
        $keys = array_merge([$path->namespace->value], explode('.', $path->leaf));
        $ref  = &$this->data;

        foreach (array_slice($keys, 0, -1) as $key) {
            if (! array_key_exists($key, $ref)) {
                $ref[$key] = [];
            }

            if (! is_array($ref[$key])) {
                throw new InvalidStatePathException(
                    "Cannot write path '{$path->toString()}': segment '{$key}' points to scalar value."
                );
            }

            $ref = &$ref[$key];
        }

        $leafKey       = $keys[array_key_last($keys)];
        $ref[$leafKey] = $value;
    }
}
