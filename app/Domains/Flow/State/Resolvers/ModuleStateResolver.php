<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Exceptions\UnknownDataAccessorNamespacePrefixException;
use App\Domains\Flow\State\Exceptions\InvalidStatePathException;
use App\Domains\Flow\State\Exceptions\ReadonlyNamespaceException;
use App\Domains\Flow\State\FlowState;
use App\Domains\Flow\State\StatePath;
use App\Domains\Flow\State\WriteContext;

final class ModuleStateResolver implements NamespaceResolverInterface
{
    public function __construct(
        private readonly DataAccessorRegistryInterface $registry,
        private readonly ModuleResolutionContext $context,
    ) {
    }

    public function get(StatePath $path, FlowState $state): mixed
    {
        $owner = $path->owner;

        if (null === $owner) {
            throw new InvalidStatePathException("Namespace 'module' requires owner segment.");
        }

        $prefix = "module.{$owner}";

        try {
            return $this->registry->resolve($prefix)->get(
                $path->leaf,
                $this->context->contactId(),
                $this->context->tenantId(),
            );
        } catch (UnknownDataAccessorNamespacePrefixException $exception) {
            throw new InvalidStatePathException($exception->getMessage(), previous: $exception);
        }
    }

    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void
    {
        $owner = $path->owner;

        if (null === $owner) {
            throw new InvalidStatePathException("Namespace 'module' requires owner segment.");
        }

        throw new ReadonlyNamespaceException(
            "Namespace 'module' is read-only. Writes are not supported by DataAccessor contract."
        );
    }
}
