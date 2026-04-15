<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Handlers\Abstract\AbstractVersionedHandler;
use App\Domains\Flow\State\SystemStateKeys;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;

final class SendMessageNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "send_message";

    private const string EXTERNAL_MESSAGE_ID_META = "external_message_id";

    public function __construct(
        private readonly MessageSenderInterface $sender,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $sentIds = data_get($state, SystemStateKeys::SENT_MESSAGES, []);
        $nodeId  = $context->nodeId;

        if (is_array($sentIds) && array_key_exists($nodeId, $sentIds)) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
            );
        }

        $externalMessageId = $this->sender->send(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            payload: is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [],
        );

        if ( ! is_array($sentIds)) {
            $sentIds = [];
        }

        $sentIds[$nodeId] = $externalMessageId;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: [SystemStateKeys::SENT_MESSAGES => $sentIds],
            metadata: [self::EXTERNAL_MESSAGE_ID_META => $externalMessageId],
        );
    }
}
