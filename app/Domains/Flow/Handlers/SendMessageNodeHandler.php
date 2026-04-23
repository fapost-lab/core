<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Abstract\AbstractVersionedHandler;
use App\Domains\Flow\State\SystemStateKeys;
use App\Jobs\Flow\ResumeTimedOutSendMessageNodeJob;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\Flow\Enums\KeyboardMode;
use JsonException;
use Ramsey\Uuid\Uuid;

final class SendMessageNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'send_message';

    private const string EXTERNAL_MESSAGE_ID_META = 'external_message_id';
    private const string NO_RESPONSE_HANDLE       = 'no_response';

    public function __construct(
        private readonly MessageSenderInterface $sender,
        private readonly ContentTranslatorInterface $translator,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [
            'required' => ['content_type'],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $sentIds          = data_get($state, SystemStateKeys::SENT_MESSAGES, []);
        $nodeId           = $context->nodeId;
        $config           = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $normalized       = $this->normalizeConfig($config);
        $isInlineKeyboard = SendMessageContentType::TextWithKeyboard === $normalized['content_type']
            && KeyboardMode::Inline === $normalized['keyboard_mode'];

        if ($isInlineKeyboard && null !== $context->incoming) {
            return $this->resumeInlineKeyboard($normalized, $state, $context);
        }

        if (is_array($sentIds) && array_key_exists($nodeId, $sentIds)) {
            return $isInlineKeyboard
                ? NodeExecutionResult::waiting()
                : NodeExecutionResult::executed();
        }

        $externalMessageId = $this->sender->send(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            payload: $this->resolveMultilingualPayload($normalized, $context),
        );

        if ( ! is_array($sentIds)) {
            $sentIds = [];
        }

        $sentIds[$nodeId] = $externalMessageId;

        $stateChanges = [
            SystemStateKeys::SENT_MESSAGES => $sentIds,
        ];

        if ($isInlineKeyboard && null !== $normalized['timeout_seconds']) {
            $timeoutAt                                                                    = now()->addSeconds($normalized['timeout_seconds']);
            $stateChanges[SystemStateKeys::SEND_MESSAGE_TIMEOUT_PREFIX . ".{$nodeId}.at"] = $timeoutAt->toIso8601String();

            ResumeTimedOutSendMessageNodeJob::dispatch(
                sessionId: $context->sessionId,
                nodeId: $nodeId,
                platform: $context->platform,
            )->delay($timeoutAt)->afterCommit();
        }

        return $isInlineKeyboard
            ? NodeExecutionResult::waiting(
                stateChanges: $stateChanges,
                metadata: [self::EXTERNAL_MESSAGE_ID_META => $externalMessageId],
            )
            : NodeExecutionResult::executed(
                stateChanges: $stateChanges,
                metadata: [self::EXTERNAL_MESSAGE_ID_META => $externalMessageId],
            );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveMultilingualPayload(array $config, NodeExecutionContext $context): array
    {
        if (isset($config['text']) && (is_array($config['text']) || is_string($config['text']))) {
            $config['text'] = $this->translator->resolveField($config['text'], $context->resolvedLanguage);
        }

        if (isset($config['caption']) && (is_array($config['caption']) || is_string($config['caption']))) {
            $config['caption'] = $this->translator->resolveField($config['caption'], $context->resolvedLanguage);
        }

        $buttons = $config['buttons'] ?? null;

        if (is_array($buttons)) {
            foreach ($buttons as $index => $button) {
                if ( ! is_array($button) || ! array_key_exists('label', $button)) {
                    continue;
                }

                if (is_array($button['label']) || is_string($button['label'])) {
                    $buttons[$index]['label'] = $this->translator->resolveField($button['label'], $context->resolvedLanguage);
                }
            }

            $config['buttons'] = $buttons;
        }

        $config['content_type']    = $config['content_type']->value;
        $config['keyboard_mode']   = $config['keyboard_mode']?->value;
        $config['session_id']      = $context->sessionId;
        $config['node_id']         = $context->nodeId;
        $config['idempotency_key'] = $context->idempotencyKey;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   content_type: SendMessageContentType,
     *   text?: array<string, mixed>|string|null,
     *   keyboard_mode: KeyboardMode|null,
     *   buttons: list<array<string, mixed>>,
     *   media_url: string|null,
     *   caption?: array<string, mixed>|string|null,
     *   timeout_seconds: int|null
     * }
     */
    private function normalizeConfig(array $config): array
    {
        $rawContentType = $config['content_type'] ?? null;
        $contentType    = is_string($rawContentType)
            ? SendMessageContentType::tryFrom($rawContentType)
            : null;

        if ( ! $contentType instanceof SendMessageContentType) {
            throw new InvalidNodeConfigException('send_message node requires a supported content_type.');
        }

        $keyboardMode = null;
        if (SendMessageContentType::TextWithKeyboard === $contentType) {
            $rawKeyboardMode = $config['keyboard_mode'] ?? null;
            $keyboardMode    = is_string($rawKeyboardMode)
                ? KeyboardMode::tryFrom($rawKeyboardMode)
                : null;

            if ( ! $keyboardMode instanceof KeyboardMode) {
                throw new InvalidNodeConfigException('send_message text_with_keyboard requires keyboard_mode.');
            }
        }

        $buttons = is_array($config['buttons'] ?? null) ? array_values($config['buttons']) : [];

        if (SendMessageContentType::TextWithKeyboard === $contentType && [] === $buttons) {
            throw new InvalidNodeConfigException('send_message text_with_keyboard requires at least one button.');
        }

        if (
            in_array($contentType, [SendMessageContentType::Text, SendMessageContentType::TextWithKeyboard], true)
            && ! array_key_exists('text', $config)
        ) {
            throw new InvalidNodeConfigException('send_message text content requires text.');
        }

        if ($contentType->requiresMediaUrl() && ! is_string($config['media_url'] ?? null)) {
            throw new InvalidNodeConfigException("send_message {$contentType->value} requires media_url.");
        }

        foreach ($buttons as $button) {
            if ( ! is_array($button) || ! Uuid::isValid((string) ($button['id'] ?? ''))) {
                throw new InvalidNodeConfigException('send_message buttons must contain stable UUID ids.');
            }

            if (
                KeyboardMode::Inline === $keyboardMode
                && ( ! is_string($button['value'] ?? null) || '' === $button['value'])
            ) {
                throw new InvalidNodeConfigException('send_message inline buttons require non-empty value.');
            }
        }

        return [
            'content_type'    => $contentType,
            'text'            => $config['text'] ?? null,
            'keyboard_mode'   => $keyboardMode,
            'buttons'         => $buttons,
            'media_url'       => is_string($config['media_url'] ?? null) ? $config['media_url'] : null,
            'caption'         => $config['caption'] ?? null,
            'timeout_seconds' => is_numeric($config['timeout_seconds'] ?? null) ? (int) $config['timeout_seconds'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     */
    private function resumeInlineKeyboard(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $responsePath   = SystemStateKeys::SEND_MESSAGE_RESPONSE_PREFIX . ".{$context->nodeId}";
        $storedHandle   = data_get($state, "{$responsePath}.handle");
        $storedUpdateId = data_get($state, "{$responsePath}.update_id");

        if (is_string($storedHandle) && $storedUpdateId === $context->incoming?->updateId) {
            return NodeExecutionResult::executed(sourceHandle: $storedHandle);
        }

        if (true === ($context->incoming?->payload['send_message_timeout'] ?? false)) {
            return NodeExecutionResult::executed(
                sourceHandle: self::NO_RESPONSE_HANDLE,
                stateChanges: [
                    "{$responsePath}.handle"    => self::NO_RESPONSE_HANDLE,
                    "{$responsePath}.update_id" => (string) $context->incoming?->updateId,
                ],
            );
        }

        $callbackPayload = $this->decodeCallbackData($context->incoming?->text);

        if (($callbackPayload['session_id'] ?? null) !== $context->sessionId) {
            return NodeExecutionResult::waiting();
        }

        $buttonId = $callbackPayload['button_id'] ?? null;
        if ( ! is_string($buttonId) || '' === $buttonId) {
            return NodeExecutionResult::waiting();
        }

        foreach ($config['buttons'] as $button) {
            if (($button['id'] ?? null) !== $buttonId) {
                continue;
            }

            $handle = (string) ($button['value'] ?? '');

            return NodeExecutionResult::executed(
                sourceHandle: $handle,
                stateChanges: [
                    "{$responsePath}.handle"    => $handle,
                    "{$responsePath}.update_id" => (string) $context->incoming?->updateId,
                ],
            );
        }

        return NodeExecutionResult::waiting();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCallbackData(?string $callbackData): array
    {
        if (null === $callbackData || '' === $callbackData) {
            return [];
        }

        try {
            $decoded = json_decode($callbackData, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
