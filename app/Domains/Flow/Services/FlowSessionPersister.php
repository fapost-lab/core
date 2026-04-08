<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\Models\FlowSession;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;

/**
 * Persists session state and column updates. Navigation position is authoritative on
 * {@see FlowSession::$current_node_id} only; state JSON is not used for runtime routing.
 */
final class FlowSessionPersister
{
    public function persist(
        FlowSession $session,
        NodeExecutionResult $result,
        ?string $nextNodeId,
    ): void {
        $state = $this->applyStateChanges($session->state ?? [], $result->stateChanges);

        $columnPatch = $this->resolveColumnPatch($result, $nextNodeId);

        $attributes = array_merge(
            [
                'state' => $state,
            ],
            $columnPatch,
        );

        try {
            $session->saveWithOptimisticLock($attributes);
        } catch (OptimisticLockConflictException $exception) {
            throw FlowConcurrencyException::forSession((string) $session->getKey(), $exception);
        }
    }

    /**
     * Maps execution outcome to {@see FlowSession} columns. Only {@code current_node_id} drives
     * navigation; omitted keys are left unchanged by the UPDATE.
     *
     * @return array<string, mixed>
     */
    private function resolveColumnPatch(NodeExecutionResult $result, ?string $nextNodeId): array
    {
        return match ($result->status) {
            NodeExecutionStatus::Completed => $this->patchForCompleted($nextNodeId),
            NodeExecutionStatus::Waiting   => [
                'status' => FlowSessionStatus::WaitingInput,
            ],
            NodeExecutionStatus::Delayed => [
                'status' => FlowSessionStatus::Paused,
            ],
            NodeExecutionStatus::Failed => [
                'status' => FlowSessionStatus::Failed,
            ],
            NodeExecutionStatus::Finished => [
                'current_node_id' => null,
                'status'          => FlowSessionStatus::Completed,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function patchForCompleted(?string $nextNodeId): array
    {
        if (null !== $nextNodeId) {
            return [
                'current_node_id' => $nextNodeId,
                'status'          => FlowSessionStatus::Active,
            ];
        }

        return [
            'current_node_id' => null,
            'status'          => FlowSessionStatus::Completed,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function applyStateChanges(array $state, array $changes): array
    {
        foreach ($changes as $flatKey => $value) {
            if ( ! is_string($flatKey) || ! str_contains($flatKey, '.')) {
                continue;
            }

            [$namespace, $path] = explode('.', $flatKey, 2);
            $state              = $this->setNamespacedPath($state, $namespace, $path, $value);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function setNamespacedPath(array $state, string $namespace, string $path, mixed $value): array
    {
        if ( ! isset($state[$namespace]) || ! is_array($state[$namespace])) {
            $state[$namespace] = [];
        }

        $keys  = explode('.', $path);
        $leaf  = $keys[array_key_last($keys)];
        $ref   = &$state[$namespace];
        $inner = array_slice($keys, 0, -1);

        foreach ($inner as $segment) {
            if ( ! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }

        $ref[$leaf] = $value;

        return $state;
    }
}
