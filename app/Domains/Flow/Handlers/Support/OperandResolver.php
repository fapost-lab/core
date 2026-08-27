<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Support;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Exceptions\UnknownDataAccessorNamespacePrefixException;
use App\Domains\Flow\State\Variables\Variable;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\Flow\Enums\StateNamespace;
use InvalidArgumentException;

/**
 * Resolves a node's left operand into a [logging-path, value] pair.
 *
 * Understands the structured operand format written by the builder's operand
 * picker — `user_variable` (resolved through {@see VariableResolverInterface}
 * with type coercion) and `source` (a `namespace.field` path) — as well as the
 * legacy plain-string path. `module.*` paths resolve through the data accessor
 * registry. Shared by Branch and Auth nodes so operand semantics live once.
 */
final class OperandResolver
{
    public function __construct(
        private readonly DataAccessorRegistryInterface $accessors,
        private readonly VariableResolverInterface $variableResolver,
    ) {
    }

    /**
     * @param  array<string, mixed>  $operand  The rule/config block carrying `left` (or null).
     * @param  string|null           $defaultPath  Node-level fallback path (e.g. branch `check`).
     * @param  array<string, mixed>  $state
     * @return array{0: string|null, 1: mixed}  [path-for-logging, resolved value]
     */
    public function resolve(
        array $operand,
        ?string $defaultPath,
        array $state,
        NodeExecutionContext $context,
    ): array {
        $left = $operand['left'] ?? null;

        if (is_array($left)) {
            $ref = $left['ref'] ?? null;

            if ('user_variable' === $ref) {
                return $this->resolveUserVariableLeft($left, $state, $context);
            }

            if ('source' === $ref) {
                return $this->resolveSourceLeft($left, $state, $context);
            }

            throw new InvalidNodeConfigException("operand: unknown left.ref '{$ref}'");
        }

        if (is_string($left) && '' !== $left) {
            return [$left, $this->resolveLegacyPath($left, $state, $context)];
        }

        if (null !== $defaultPath) {
            return [$defaultPath, $this->resolveLegacyPath($defaultPath, $state, $context)];
        }

        throw new InvalidNodeConfigException('operand: must define left operand or node must define a default path');
    }

    /**
     * Resolve a bare path string against state / module accessors.
     *
     * @param  array<string, mixed>  $state
     */
    public function resolveLegacyPath(string $path, array $state, NodeExecutionContext $context): mixed
    {
        if (str_starts_with($path, 'module.')) {
            [$prefix, $key] = $this->splitModulePath($path);

            try {
                $accessor = $this->accessors->resolve($prefix);
            } catch (UnknownDataAccessorNamespacePrefixException $exception) {
                throw new InvalidNodeConfigException($exception->getMessage(), previous: $exception);
            }

            return $accessor->get($key, $context->contactId, $context->tenantId);
        }

        if (
            str_starts_with($path, StateNamespace::Flow->value . '.')
            || str_starts_with($path, StateNamespace::System->value . '.')
            || str_starts_with($path, StateNamespace::Rag->value . '.')
            || str_starts_with($path, 'contact.')
            || str_starts_with($path, 'call.')
        ) {
            return $this->resolveWithLength($state, $path);
        }

        throw new InvalidNodeConfigException("operand: unsupported namespace in path '{$path}'");
    }

    /**
     * Resolve a state path, honouring the `.length` pseudo-accessor for array
     * variables (spec §5.6 / §14.3). `contact.photos.length` yields the element
     * count so conditions can compare array size (e.g. "photos.length >= 5").
     *
     * A real state key literally named `length` still wins when it exists; the
     * pseudo-accessor only kicks in when `data_get` finds nothing and the parent
     * value is a countable array.
     *
     * @param  array<string, mixed>  $state
     */
    private function resolveWithLength(array $state, string $path): mixed
    {
        $value = data_get($state, $path);

        if (null !== $value || ! str_ends_with($path, '.length')) {
            return $value;
        }

        $parent      = mb_substr($path, 0, -mb_strlen('.length'));
        $parentValue = data_get($state, $parent);

        return is_array($parentValue) ? count($parentValue) : $value;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $state
     * @return array{0: string, 1: mixed}
     */
    private function resolveUserVariableLeft(array $left, array $state, NodeExecutionContext $context): array
    {
        $variableConfig = $left['variable'] ?? null;

        if (! is_array($variableConfig)) {
            $variableConfig = [
                'name'    => $left['name'] ?? null,
                'storage' => $left['storage'] ?? null,
                'group'   => $left['group'] ?? null,
            ];
        }

        try {
            $variable = Variable::tryFromArray($variableConfig);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidNodeConfigException(
                'operand: invalid user_variable left — ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (! $variable instanceof Variable) {
            throw new InvalidNodeConfigException('operand: user_variable left missing required name/storage');
        }

        $path = $this->variableResolver->resolveTargetPath($variable);

        $value = null !== $context->stateReader
            ? $this->variableResolver->read($variable, $context)
            : data_get($state, $path);

        return [$path, $value];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $state
     * @return array{0: string, 1: mixed}
     */
    private function resolveSourceLeft(array $left, array $state, NodeExecutionContext $context): array
    {
        $source = is_string($left['source'] ?? null) ? $left['source'] : null;
        // Support both `field` and `path` as aliases for the attribute name within the source.
        $field = is_string($left['field'] ?? null) ? $left['field']
                : (is_string($left['path'] ?? null) ? $left['path'] : null);

        if (null === $source || '' === $source) {
            throw new InvalidNodeConfigException('operand: source left requires non-empty source');
        }

        if (null === $field || '' === $field) {
            throw new InvalidNodeConfigException('operand: source left requires non-empty field');
        }

        $path = "{$source}.{$field}";

        return [$path, $this->resolveLegacyPath($path, $state, $context)];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitModulePath(string $path): array
    {
        $segments = explode('.', $path, 4);

        if (count($segments) < 3 || 'module' !== $segments[0] || '' === $segments[1]) {
            throw new InvalidNodeConfigException("operand: invalid module path '{$path}'");
        }

        $prefix = "{$segments[0]}.{$segments[1]}";
        $key    = implode('.', array_slice($segments, 2));

        if ('' === $key) {
            throw new InvalidNodeConfigException("operand: invalid module path '{$path}'");
        }

        return [$prefix, $key];
    }
}
