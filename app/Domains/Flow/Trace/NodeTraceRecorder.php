<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Throwable;

/**
 * Records the node trace of a session: `node_entered` before a node runs, `node_completed` when it returned a result,
 * `node_failed` when the session failed at it. The engine calls it for every node of every session.
 *
 * A row's `metadata` is its envelope: the node's type and version, the loops it runs in, and for `node_completed` its
 * status, handle, next node, duration, error message and — for an automation run while {@see self::PAYLOADS_SETTING}
 * is on — its `input` and `output`. A conversation's rows never carry `input`/`output`: nothing deletes them yet, and a
 * conversation is a person's data (gate decision D-3). Payloads are masked ({@see TracePayloadMasker}), then limited
 * ({@see TracePayloadLimiter}), and written nowhere else.
 *
 * Nothing here may break a step: every failure, from building the envelope to the insert, ends in the warning
 * `flow.trace.write_failed`. Rows are written outside the step's transaction, like `flow_logs`. One screen reads them,
 * statuses only: the run graph ({@see RunGraphReader}) selects a whitelist of envelope fields and never `input`,
 * `output` or the error message, which wait for `ViewRunPayloads`. The session page's history
 * ({@see \App\Domains\Flow\Services\FlowSessionInspector}) filters out {@see self::EVENT_TYPES}.
 */
final readonly class NodeTraceRecorder
{
    /** Platform setting: whether automation trace rows carry the node's input and output. */
    public const string PAYLOADS_SETTING = 'flow.trace.payloads';

    /** @var list<string> the history event types of the node trace */
    public const array EVENT_TYPES = [
        HistoryEventType::NodeEntered->value,
        HistoryEventType::NodeCompleted->value,
        HistoryEventType::NodeFailed->value,
    ];

    /** The error class of a session failed for crossing a step limit. */
    public const string STEP_LIMIT_ERROR = 'step_limit';

    /** The node type whose incoming text is a one-time code; kept as a literal so the trace does not depend on handlers. */
    private const string AUTH_REQUEST_TYPE = 'auth_request';

    public function __construct(
        private NodeTraceWriter $writer,
        private TracePayloadMasker $masker,
        private TracePayloadLimiter $limiter,
        private PlatformSettingsReaderInterface $settings,
    ) {
    }

    /**
     * @param  array<string, int>  $iterations  {@see LoopIterations::of()}
     */
    public function entered(FlowSession $session, string $nodeId, string $type, int $version, array $iterations): void
    {
        $this->record(HistoryEventType::NodeEntered, $session, $nodeId, fn (): array => self::withIterations(['type' => $type, 'version' => $version], $iterations));
    }

    /**
     * @param  array<string, int>  $iterations
     */
    public function completed(
        FlowSession $session,
        string $nodeId,
        string $type,
        int $version,
        NodeExecutionResult $result,
        ?string $nextNodeId,
        float $durationMs,
        ?IncomingMessage $incoming,
        bool $resumedAfterDelay,
        array $iterations,
    ): void {
        $this->record(HistoryEventType::NodeCompleted, $session, $nodeId, function () use (
            $session,
            $type,
            $version,
            $result,
            $nextNodeId,
            $durationMs,
            $incoming,
            $resumedAfterDelay,
            $iterations,
        ): array {
            $envelope = self::withIterations([
                'type'        => $type,
                'version'     => $version,
                'status'      => $result->status->value,
                'handle'      => $result->sourceHandle,
                'next'        => $nextNodeId,
                'duration_ms' => round($durationMs, 3),
            ], $iterations);

            if ($this->carriesPayloads($session)) {
                $envelope['input']  = $this->masker->mask($this->input($type, $result, $incoming, $resumedAfterDelay));
                $envelope['output'] = $this->masker->mask($this->output($result));
            }

            if (null !== $result->errorMessage && '' !== $result->errorMessage) {
                $envelope['error'] = ['message' => $this->masker->mask($result->errorMessage)];
            }

            return $envelope;
        });
    }

    /**
     * @param  string  $errorClass  the exception class, or {@see self::STEP_LIMIT_ERROR}
     * @param  array<string, int>  $iterations
     */
    public function failed(
        FlowSession $session,
        string $nodeId,
        ?string $type,
        ?int $version,
        string $errorClass,
        string $message,
        ?float $durationMs,
        array $iterations,
    ): void {
        $this->record(HistoryEventType::NodeFailed, $session, $nodeId, function () use (
            $type,
            $version,
            $errorClass,
            $message,
            $durationMs,
            $iterations,
        ): array {
            $envelope = array_filter([
                'type'        => $type,
                'version'     => $version,
                'duration_ms' => null === $durationMs ? null : round($durationMs, 3),
            ], static fn (mixed $value): bool => null !== $value);

            $envelope          = self::withIterations($envelope, $iterations);
            $envelope['error'] = ['class' => $errorClass, 'message' => $this->masker->mask($message)];

            return $envelope;
        });
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, int>  $iterations
     *
     * @return array<string, mixed>
     */
    private static function withIterations(array $envelope, array $iterations): array
    {
        if ([] !== $iterations) {
            $envelope['iterations'] = $iterations;
        }

        return $envelope;
    }

    /**
     * @param  callable(): array<string, mixed>  $envelope
     */
    private function record(HistoryEventType $eventType, FlowSession $session, string $nodeId, callable $envelope): void
    {
        $sessionId = (string) $session->getKey();

        try {
            $metadata = $this->limiter->limit($envelope());
        } catch (Throwable $exception) {
            $this->writer->failed($eventType, $sessionId, $nodeId, $exception);

            return;
        }

        $this->writer->write($eventType, (string) $session->tenant_id, $sessionId, $nodeId, $metadata);
    }

    private function carriesPayloads(FlowSession $session): bool
    {
        return FlowRunKind::Automation === $session->run_kind && $this->settings->boolean(self::PAYLOADS_SETTING);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(string $type, NodeExecutionResult $result, ?IncomingMessage $incoming, bool $resumedAfterDelay): array
    {
        $input = [];

        if (null !== $incoming) {
            $input['message'] = array_filter([
                'type' => $incoming->type->value,
                // What a contact types to an auth_request is a one-time code: it never reaches the trace.
                'text'    => self::AUTH_REQUEST_TYPE === $type ? null : $incoming->text,
                'payload' => [] === $incoming->payload ? null : $incoming->payload,
                'media'   => [] === $incoming->media ? null : count($incoming->media),
            ], static fn (mixed $value): bool => null !== $value);
        }

        if ($resumedAfterDelay) {
            $input['resumed_after_delay'] = true;
        }

        if (is_array($result->trace['input'] ?? null) && [] !== $result->trace['input']) {
            $input['node'] = $result->trace['input'];
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function output(NodeExecutionResult $result): array
    {
        $output = [];

        if ([] !== $result->stateChanges) {
            $output['state_changes'] = $result->stateChanges;
        }

        if ([] !== $result->metadata) {
            $output['metadata'] = $result->metadata;
        }

        if (is_array($result->trace['output'] ?? null) && [] !== $result->trace['output']) {
            $output['node'] = $result->trace['output'];
        }

        return $output;
    }
}
