<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
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
        private readonly ContentTranslatorInterface $translator,
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
        $config  = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];

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
            payload: $this->resolveMultilingualPayload($config, $context->resolvedLanguage),
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

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolveMultilingualPayload(array $config, string $language): array
    {
        if (isset($config['text']) && (is_array($config['text']) || is_string($config['text']))) {
            $config['text'] = $this->translator->resolveField($config['text'], $language);
        }

        $buttons = $config['buttons'] ?? null;

        if (is_array($buttons)) {
            foreach ($buttons as $index => $button) {
                if ( ! is_array($button) || ! array_key_exists('label', $button)) {
                    continue;
                }

                if (is_array($button['label']) || is_string($button['label'])) {
                    $buttons[$index]['label'] = $this->translator->resolveField($button['label'], $language);
                }
            }

            $config['buttons'] = $buttons;
        }

        return $config;
    }
}
