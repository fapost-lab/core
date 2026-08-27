<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\JsonField;
use FAPost\Support\Builder\Schema\Fields\TextField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;

/**
 * Publishes a tenant-scoped event so subscribed event-triggers can asynchronously
 * start their own flows. Fire-and-forget: the engine continues from the
 * `success` handle without waiting for downstream subscribers.
 *
 * Naming is convention-only ({@code domain.entity.action}); event scope is the
 * whole tenant. Per-assistant filtering is V1.x.
 */
final class EmitEventNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'emit_event';

    private const string EVENT_TYPE_META = 'event_type';

    public function __construct(
        private readonly FlowTriggerEventPublisherInterface $publisher,
        private readonly TemplateRenderer $templates,
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
            ->section(
                Section::make('event', 'Event')
                    ->icon('bolt')
                    ->fields([
                        TextField::make('event_type')
                            ->label('Event type')
                            ->required()
                            ->placeholder('sales.order.created'),
                    ]),
            )
            ->section(
                Section::make('payload', 'Payload')
                    ->icon('cube')
                    ->fields([
                        JsonField::make('payload')
                            ->label('Payload')
                            ->default([]),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config    = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $eventType = is_string($config['event_type'] ?? null) ? mb_trim($config['event_type']) : '';

        if ('' === $eventType) {
            throw new InvalidNodeConfigException('emit_event: missing or empty event_type');
        }

        $rawPayload = is_array($config['payload'] ?? null) ? $config['payload'] : [];
        // TemplateRenderer is recursive — walks arrays, substitutes placeholders
        // in every string leaf via the per-context expression engine when wired,
        // falls back to direct session-state resolution otherwise.
        /** @var array<string, mixed> $resolvedPayload */
        $resolvedPayload = $this->templates->render($rawPayload, $context, $state);

        $this->publisher->publish(
            tenantId: $context->tenantId,
            eventName: $eventType,
            payload: $resolvedPayload,
            source: [
                'tenant_id'  => $context->tenantId,
                'session_id' => $context->sessionId,
                'node_id'    => $context->nodeId,
                'contact_id' => $context->contactId,
            ],
        );

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'success',
            metadata: [self::EVENT_TYPE_META => $eventType],
        );
    }
}
