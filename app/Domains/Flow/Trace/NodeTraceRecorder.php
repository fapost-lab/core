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
 * is on — its `input` and `output`. A conversation's rows never carry `input`/`output`: they are kept forever unless
 * an operator sets `flow.session_history.retention_days`, and a conversation is a person's data (gate decision D-3). Payloads are masked ({@see TracePayloadMasker}), then limited
 * ({@see TracePayloadLimiter}), and written nowhere else.
 *
 * Nothing here may break a step: every failure, from building the envelope to the insert, ends in the warning
 * `flow.trace.write_failed`. Rows are written outside the step's transaction, like `flow_logs`. One screen reads them:
 * the run graph draws statuses ({@see RunGraphReader}, a whitelist of envelope fields, never `input`, `output` or the
 * error message), and shows a holder of `ViewRunPayloads` one visit's data at a time ({@see RunPayloadReader}). The
 * session page's history ({@see \App\Domains\Flow\Services\FlowSessionInspector}) filters out {@see self::EVENT_TYPES}.
 *
 * Rows are written in the order the engine runs, with one exception: a `subflow` node whose child ends in the same run.
 * The child's end resumes the parent from inside the subflow handler ({@see \App\Domains\Flow\Subflow\DefaultSubflowResumer}),
 * so the parent's following nodes write their rows first, and the subflow node's `node_completed` (status `waiting`)
 * comes after them, its `duration_ms` covering that continuation. A reader must not take `created_at` across nodes as
 * the execution order of a subflow node.
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

    public function entered(NodeTraceContext $context): void
    {
        $this->record(HistoryEventType::NodeEntered, $context, fn (): array => self::withIterations(['type' => $context->type, 'version' => $context->version], $context->iterations));
    }

    public function completed(
        NodeTraceContext $context,
        NodeExecutionResult $result,
        ?string $nextNodeId,
        float $durationMs,
        ?IncomingMessage $incoming,
        bool $resumedAfterDelay,
    ): void {
        $this->record(HistoryEventType::NodeCompleted, $context, function () use (
            $context,
            $result,
            $nextNodeId,
            $durationMs,
            $incoming,
            $resumedAfterDelay,
        ): array {
            $envelope = self::withIterations([
                'type'        => $context->type,
                'version'     => $context->version,
                'status'      => $result->status->value,
                'handle'      => $result->sourceHandle,
                'next'        => $nextNodeId,
                'duration_ms' => round($durationMs, 3),
            ], $context->iterations);

            if ($this->carriesPayloads($context->session)) {
                $envelope['input']  = $this->masker->mask($this->input((string) $context->type, $result, $incoming, $resumedAfterDelay));
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
     */
    public function failed(NodeTraceContext $context, string $errorClass, string $message, ?float $durationMs): void
    {
        $this->record(HistoryEventType::NodeFailed, $context, function () use ($context, $errorClass, $message, $durationMs): array {
            $envelope = array_filter([
                'type'        => $context->type,
                'version'     => $context->version,
                'duration_ms' => null === $durationMs ? null : round($durationMs, 3),
            ], static fn (mixed $value): bool => null !== $value);

            $envelope          = self::withIterations($envelope, $context->iterations);
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
    private function record(HistoryEventType $eventType, NodeTraceContext $context, callable $envelope): void
    {
        $session   = $context->session;
        $nodeId    = $context->nodeId;
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
