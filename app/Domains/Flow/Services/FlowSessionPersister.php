<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\Exceptions\StateNamespaceViolationException;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\State\SystemStateNamespacePolicy;
use DateTimeInterface;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Enums\StateNamespace;

/**
 * Persists session state and column updates. Navigation position is authoritative on
 * {@see FlowSession::$current_node_id} only; state JSON is not used for runtime routing.
 *
 * State changes returned by handlers are validated against the namespace contract
 * ({@see SystemStateNamespacePolicy}) before they are merged into the session JSON.
 */
final class FlowSessionPersister
{
    public function __construct(
        private readonly SystemStateNamespacePolicy $namespacePolicy,
    ) {
    }

    /**
     * Persist a session terminated by an explicit {@code end} node. Differs from
     * the regular {@see persist()} path: status is forced to {@code ended}
     * (not {@code completed}) and {@code end_status} is recorded so subflow
     * parents can pick the right resume handle. State changes accumulated by
     * the handler are still merged into the session JSON.
     */
    public function persistEnd(
        FlowSession $session,
        NodeExecutionResult $result,
        string $endStatus,
        string $nodeType,
        string $nodeId,
    ): void {
        $state = $this->applyStateChanges($session->state ?? [], $result->stateChanges, $nodeType);
        $state = $this->clearDelayedMarker($state, $nodeId);

        try {
            $session->saveWithOptimisticLock([
                'state'           => $state,
                'current_node_id' => null,
                'status'          => FlowSessionStatus::Ended,
                'end_status'      => $endStatus,
            ]);
        } catch (OptimisticLockConflictException $exception) {
            throw FlowConcurrencyException::forSession((string)$session->getKey(), $exception);
        }
    }

    public function persist(
        FlowSession $session,
        NodeExecutionResult $result,
        ?string $nextNodeId,
        string $nodeType,
        string $nodeId,
    ): void {
        $state = $this->applyStateChanges($session->state ?? [], $result->stateChanges, $nodeType);

        $state = NodeExecutionStatus::Delayed === $result->status && null !== $result->resumeAt
            ? $this->setDelayedMarker($state, $nodeId, $result->resumeAt)
            : $this->clearDelayedMarker($state, $nodeId);

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
            throw FlowConcurrencyException::forSession((string)$session->getKey(), $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $changes
     *
     * @return array<string, mixed>
     *
     * @throws StateNamespaceViolationException
     */
    private function applyStateChanges(array $state, array $changes, string $nodeType): array
    {
        foreach ($changes as $flatKey => $value) {
            if (! is_string($flatKey) || ! str_contains($flatKey, '.')) {
                continue;
            }

            $this->namespacePolicy->assertWriteAllowed($nodeType, $flatKey);

            [$namespace, $path] = explode('.', $flatKey, 2);
            $state              = $this->setNamespacedPath($state, $namespace, $path, $value);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function setNamespacedPath(array $state, string $namespace, string $path, mixed $value): array
    {
        if (! isset($state[$namespace]) || ! is_array($state[$namespace])) {
            $state[$namespace] = [];
        }

        $keys  = explode('.', $path);
        $leaf  = $keys[array_key_last($keys)];
        $ref   = &$state[$namespace];
        $inner = array_slice($keys, 0, -1);

        foreach ($inner as $segment) {
            if (! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }

        $ref[$leaf] = $value;

        return $state;
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
            NodeExecutionStatus::Executed => $this->patchForCompleted($nextNodeId),
            NodeExecutionStatus::Waiting  => [
                'status' => FlowSessionStatus::WaitingInput,
            ],
            // `delayed()` without a `resumeAt` has no resume time to act on, so it
            // parks like `waiting()`: the next inbound message re-runs the node,
            // which decides whether to move on. With a `resumeAt` the session
            // parks on `paused` instead — see {@see setDelayedMarker()} for the
            // marker that {@see \App\Domains\Flow\Orchestration\DelayedSessionResumer}
            // and the routing pipeline key off to wake it.
            NodeExecutionStatus::Delayed => [
                'status' => null !== $result->resumeAt ? FlowSessionStatus::Paused : FlowSessionStatus::WaitingInput,
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
     * Writes the engine-owned `system.delayed.{nodeId}.resume_at` marker
     * directly into the state array — bypassing {@see applyStateChanges()}
     * and the {@see SystemStateNamespacePolicy} allowlist it enforces, since
     * this key is never part of a handler's `stateChanges`.
     *
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function setDelayedMarker(array $state, string $nodeId, DateTimeInterface $resumeAt): array
    {
        return $this->setNamespacedPath(
            $state,
            StateNamespace::System->value,
            "delayed.{$nodeId}.resume_at",
            $resumeAt->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * Clears the marker set by {@see setDelayedMarker()} once the node
     * returns anything other than `delayed(resumeAt: ...)` — a no-op when no
     * marker was set for this node.
     *
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function clearDelayedMarker(array $state, string $nodeId): array
    {
        if (null === data_get($state, SystemStateKeys::DELAYED_RESULT_PREFIX . ".{$nodeId}")) {
            return $state;
        }

        return $this->setNamespacedPath(
            $state,
            StateNamespace::System->value,
            "delayed.{$nodeId}",
            null,
        );
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
}
