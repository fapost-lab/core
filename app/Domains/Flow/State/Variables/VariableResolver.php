<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Variables;

use App\Domains\Flow\Contracts\VariableCoercerInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\State\FlowStateNamespace;
use Closure;
use FAPost\Foundation\DTO\NodeExecutionContext;
use InvalidArgumentException;

/**
 * Default implementation of {@see VariableResolverInterface}.
 *
 * Reads raw values from the engine state and coerces them via the per-tenant
 * schema registry + VariableCoercer. Falls back to raw value when the variable's
 * type is unknown (legacy flow / no declaration). Bound as singleton in FlowServiceProvider.
 *
 * The schema registry is resolved lazily via a closure so the singleton does not
 * capture a scoped instance — important for long-lived queue worker processes.
 */
final class VariableResolver implements VariableResolverInterface
{
    private readonly VariableCoercerInterface $coercer;

    /** @var (Closure(): VariableSchemaRegistryInterface)|null */
    private readonly ?Closure $schemaRegistryResolver;

    /**
     * @param  (Closure(): VariableSchemaRegistryInterface)|null  $schemaRegistryResolver
     */
    public function __construct(
        ?VariableCoercerInterface $coercer = null,
        ?Closure $schemaRegistryResolver = null,
    ) {
        $this->coercer                = $coercer ?? new VariableCoercer();
        $this->schemaRegistryResolver = $schemaRegistryResolver;
    }

    public function resolveTargetPath(Variable $variable): string
    {
        return match ($variable->storage) {
            VariableStorage::Session => $this->sessionPath($variable),
            VariableStorage::Contact => $this->contactPath($variable),
        };
    }

    public function read(Variable $variable, NodeExecutionContext $context): mixed
    {
        $reader = $context->stateReader;

        if (null === $reader) {
            return null;
        }

        $raw = $reader->read($this->resolveTargetPath($variable));

        // Resolve the declared type: prefer the variable's own type annotation;
        // fall back to the tenant schema registry for undeclared variables
        // (e.g. when the variable is read from a different flow's saved data).
        $type = $variable->type;

        if (null === $type && null !== $this->schemaRegistryResolver) {
            $registry = ($this->schemaRegistryResolver)();
            $type     = $registry->get(
                $variable->storage->value,
                $variable->group,
                $variable->name,
            );
        }

        if (null === $type) {
            return $raw;
        }

        return $this->coercer->coerce($raw, $type);
    }

    public function fromLegacyPath(string $path): Variable
    {
        $trimmed = mb_trim($path);

        if ('' === $trimmed) {
            throw new InvalidArgumentException('Legacy variable path must not be empty.');
        }

        $segments = explode('.', $trimmed);
        $head     = $segments[0];

        // No prefix → historical default of session-scoped flow variable.
        if (1 === count($segments)) {
            return new Variable(
                name: $head,
                storage: VariableStorage::Session,
            );
        }

        if ('flow' === $head) {
            if (count($segments) > 2) {
                throw new InvalidArgumentException(
                    "Legacy flow path '{$path}' must be flat — flow.* groups are not supported.",
                );
            }

            return new Variable(
                name: $segments[1],
                storage: VariableStorage::Session,
            );
        }

        if ('contact' === $head) {
            $count = count($segments);

            if (2 === $count) {
                return new Variable(
                    name: $segments[1],
                    storage: VariableStorage::Contact,
                );
            }

            if (3 === $count) {
                return new Variable(
                    name: $segments[2],
                    storage: VariableStorage::Contact,
                    group: $segments[1],
                );
            }

            throw new InvalidArgumentException(
                "Legacy contact path '{$path}' exceeds maximum depth (allowed: contact.<key> or contact.<group>.<key>).",
            );
        }

        throw new InvalidArgumentException("Legacy variable path '{$path}' has unsupported namespace '{$head}'.");
    }

    private function sessionPath(Variable $variable): string
    {
        // Session variables never have a group — guarded by Variable's constructor.
        return FlowStateNamespace::FLOW . ".{$variable->name}";
    }

    private function contactPath(Variable $variable): string
    {
        if (null === $variable->group) {
            return "contact.{$variable->name}";
        }

        return "contact.{$variable->group}.{$variable->name}";
    }
}
