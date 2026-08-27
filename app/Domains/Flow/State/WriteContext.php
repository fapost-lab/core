<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

final readonly class WriteContext
{
    private function __construct(
        public string $type,
        public ?string $name,
    ) {
    }

    public static function engine(): self
    {
        return new self('engine', null);
    }

    public static function node(string $nodeType): self
    {
        return new self('node', $nodeType);
    }

    public static function builder(): self
    {
        return new self('builder', null);
    }

    public static function accessor(string $moduleName): self
    {
        return new self('accessor', $moduleName);
    }

    public function isEngine(): bool
    {
        return 'engine' === $this->type;
    }

    public function isNode(string $nodeType): bool
    {
        return 'node' === $this->type && $this->name === $nodeType;
    }

    public function isBuilder(): bool
    {
        return 'builder' === $this->type;
    }

    public function isAccessor(?string $owner = null): bool
    {
        if ('accessor' !== $this->type) {
            return false;
        }

        if (null === $owner) {
            return true;
        }

        return $this->name === $owner;
    }
}
