<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Exceptions\UnknownDataAccessorNamespacePrefixException;
use App\Domains\Flow\State\FlowStateNamespace;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;

final class ConditionNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "condition";

    private const string EXPRESSION_META = "expression";

    public function __construct(
        private readonly DataAccessorRegistryInterface $accessors,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Logic';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [
            'check' => [
                'type'        => 'state-picker',
                'label'       => 'Check',
                'required'    => true,
                'placeholder' => 'flow.status',
            ],
            'rules' => [
                'type'     => 'repeater',
                'label'    => 'Rules',
                'required' => true,
            ],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $path   = $config['check'] ?? null;

        if ( ! is_string($path) || '' === $path) {
            throw new InvalidNodeConfigException('condition: missing check');
        }

        $value = $this->resolveOperandValue($path, $state, $context);

        $rules                  = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        [$handle, $matchedRule] = $this->evaluateRules($value, $rules);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $handle,
            logResolved: [
                $path => $value,
            ],
            metadata: [
                self::EXPRESSION_META => [
                    'operand'  => $path,
                    'operator' => is_array($matchedRule) ? ($matchedRule['operator'] ?? null) : null,
                    'expected' => is_array($matchedRule) ? ($matchedRule['value'] ?? null) : null,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveOperandValue(string $path, array $state, NodeExecutionContext $context): mixed
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
            str_starts_with($path, FlowStateNamespace::FLOW . '.')
            || str_starts_with($path, FlowStateNamespace::SYSTEM . '.')
            || str_starts_with($path, FlowStateNamespace::RAG . '.')
        ) {
            return data_get($state, $path);
        }

        throw new InvalidNodeConfigException("condition: unsupported namespace in check '{$path}'");
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitModulePath(string $path): array
    {
        $segments = explode('.', $path, 4);

        if (count($segments) < 3 || 'module' !== $segments[0] || '' === $segments[1]) {
            throw new InvalidNodeConfigException("condition: invalid module path '{$path}'");
        }

        $prefix = "{$segments[0]}.{$segments[1]}";
        $key    = implode('.', array_slice($segments, 2));

        if ('' === $key) {
            throw new InvalidNodeConfigException("condition: invalid module path '{$path}'");
        }

        return [$prefix, $key];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     *
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function evaluateRules(mixed $value, array $rules): array
    {
        foreach ($rules as $rule) {
            if (is_array($rule) && $this->matchesRule($value, $rule)) {
                $handle = is_string($rule['handle'] ?? null) ? $rule['handle'] : 'default';

                return [$handle, $rule];
            }
        }

        return ['default', null];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function matchesRule(mixed $value, array $rule): bool
    {
        return match ($rule['operator'] ?? null) {
            'eq'        => $value === ($rule['value'] ?? null),
            'neq'       => $value !== ($rule['value'] ?? null),
            'gt'        => $value > ($rule['value'] ?? null),
            'gte'       => $value >= ($rule['value'] ?? null),
            'lt'        => $value < ($rule['value'] ?? null),
            'lte'       => $value <= ($rule['value'] ?? null),
            'contains'  => str_contains((string)$value, (string)($rule['value'] ?? '')),
            'in'        => in_array($value, is_array($rule['value'] ?? null) ? $rule['value'] : [], true),
            'empty'     => empty($value),
            'not_empty' => ! empty($value),
            default     => false,
        };
    }
}
