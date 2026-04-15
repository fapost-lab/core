<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Abstract\AbstractVersionedHandler;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;

final class ConditionNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "condition";

    private const string RESOLVED_PATH_META  = "resolved_path";
    private const string RESOLVED_VALUE_META = "resolved_value";
    private const string HANDLE_META         = "handle";

    public function __construct(
        private readonly DataAccessorRegistryInterface $accessors,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $path   = $config['check'] ?? null;

        if ( ! is_string($path) || '' === $path) {
            throw new InvalidNodeConfigException('condition: missing check');
        }

        $value = str_starts_with($path, 'module.')
            ? $this->accessors->resolve($path, $context->contactId, $context->tenantId)
            : data_get($state, $path);

        $rules  = is_array($config['rules'] ?? null) ? $config['rules'] : [];
        $handle = $this->evaluateRules($value, $rules);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $handle,
            metadata: [
                self::RESOLVED_PATH_META  => $path,
                self::RESOLVED_VALUE_META => $value,
                self::HANDLE_META         => $handle,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function evaluateRules(mixed $value, array $rules): string
    {
        foreach ($rules as $rule) {
            if (is_array($rule) && $this->matchesRule($value, $rule)) {
                return is_string($rule['handle'] ?? null) ? $rule['handle'] : 'default';
            }
        }

        return 'default';
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
            'contains'  => str_contains((string) $value, (string) ($rule['value'] ?? '')),
            'in'        => in_array($value, is_array($rule['value'] ?? null) ? $rule['value'] : [], true),
            'empty'     => empty($value),
            'not_empty' => ! empty($value),
            default     => false,
        };
    }
}
