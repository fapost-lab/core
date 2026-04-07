<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use App\Domains\Flow\State\Exceptions\InvalidStatePathException;

final readonly class StatePath
{
    public function __construct(
        public StateNamespace $namespace,
        public ?string $owner,
        public string $leaf,
    ) {
    }

    public static function from(string $path): self
    {
        $segments = explode('.', $path);

        if (count($segments) < 2) {
            throw new InvalidStatePathException("State path must have at least two segments, got: '{$path}'");
        }

        $namespace = StateNamespace::tryFrom($segments[0]);

        if (null === $namespace) {
            throw new InvalidStatePathException("Unknown root namespace '{$segments[0]}' in path '{$path}'");
        }

        if (StateNamespace::Module === $namespace) {
            if (count($segments) < 3) {
                throw new InvalidStatePathException(
                    "module namespace requires owner and leaf segment, got: '{$path}'"
                );
            }

            $owner = $segments[1];
            $leaf  = implode('.', array_slice($segments, 2));
        } else {
            $owner = null;
            $leaf  = implode('.', array_slice($segments, 1));
        }

        if ('' === $leaf) {
            throw new InvalidStatePathException("State path leaf cannot be empty in: '{$path}'");
        }

        return new self($namespace, $owner, $leaf);
    }

    public function toString(): string
    {
        if (null !== $this->owner) {
            return "{$this->namespace->value}.{$this->owner}.{$this->leaf}";
        }

        return "{$this->namespace->value}.{$this->leaf}";
    }
}
