<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\DelayResumeSchedulerInterface;
use App\Domains\Flow\State\SystemStateKeys;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\NumberField;
use Fapost\Support\Builder\Schema\Schema;
use Illuminate\Support\Carbon;

/**
 * Pauses the flow for the configured number of seconds, then leaves through `default`.
 *
 * The first visit records `system.delay.{nodeId}.resume_at` and schedules a resume
 * for that moment. Every later visit is gated on the clock alone: before `resume_at`
 * the node keeps waiting (an inbound message is absorbed), from `resume_at` on it
 * completes and clears its marker, so a loop that comes back here starts a fresh
 * delay. A retry that schedules twice is harmless — the resume side ignores a
 * `resume_at` that no longer matches the persisted one.
 *
 * The session parks in `waiting_input` rather than `paused`: the routing pipeline
 * only looks up active and waiting sessions, so a paused one would let an inbound
 * message start a second session for the same contact.
 */
final class DelayNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "delay";

    private const string RESUME_AT_META = "resume_at";
    private const string SECONDS_META   = "seconds";

    public function __construct(
        private readonly DelayResumeSchedulerInterface $scheduler,
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
        return Schema::make()
            ->fields([
                NumberField::make('seconds')
                    ->label('Delay (seconds)')
                    ->default(60),
            ])
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $markerKey = SystemStateKeys::DELAY_NODE_PREFIX . ".{$context->nodeId}";
        $resumeAt  = data_get($state, "{$markerKey}.resume_at");

        if (is_string($resumeAt)) {
            return Carbon::now()->lt(Carbon::parse($resumeAt))
                ? NodeExecutionResult::waiting()
                : NodeExecutionResult::executed(stateChanges: [$markerKey => null]);
        }

        $config  = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $seconds = max(0, (int)($config['seconds'] ?? 60));

        if (0 === $seconds) {
            return NodeExecutionResult::executed();
        }

        $now      = Carbon::now();
        $resumeAt = $now->clone()->addSeconds($seconds);

        $this->scheduler->schedule($context->tenantId, $context->sessionId, $context->nodeId, $resumeAt);

        return NodeExecutionResult::waiting(
            stateChanges: [
                "{$markerKey}.resume_at"    => $resumeAt->toIso8601String(),
                "{$markerKey}.scheduled_at" => $now->toIso8601String(),
            ],
            metadata: [
                self::RESUME_AT_META => $resumeAt->toIso8601String(),
                self::SECONDS_META   => $seconds,
            ],
        );
    }
}
