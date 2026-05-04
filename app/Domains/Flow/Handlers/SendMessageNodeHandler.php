<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateResolver;
use App\Domains\Flow\State\FlowStateNamespace;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Jobs\Flow\ResumeTimedOutSendMessageNodeJob;
use Carbon\Carbon;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\Flow\Enums\KeyboardMode;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Ramsey\Uuid\Uuid;
use Throwable;

final class SendMessageNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'send_message';

    private const string EXTERNAL_MESSAGE_ID_META = 'external_message_id';
    private const string NO_RESPONSE_HANDLE       = 'no_response';

    public function __construct(
        private readonly MessageSenderInterface $sender,
        private readonly ContentTranslatorInterface $translator,
        private readonly TemplateResolver $templates,
        private readonly InlineKeyboardEditorInterface $keyboardEditor,
        private readonly PersistentButtonRegistryInterface $persistentButtonRegistry,
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
            'required'       => ['content_type'],
            'default_config' => [
                'content_type' => 'text',
                'text'         => '',
            ],
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

        $alreadySent = is_array($sentIds) && array_key_exists($nodeId, $sentIds);

        if ($isInlineKeyboard && $alreadySent && null !== $context->incoming) {
            return $this->resumeInlineKeyboard($normalized, $state, $context);
        }

        if ($alreadySent) {
            return $isInlineKeyboard
                ? NodeExecutionResult::waiting()
                : NodeExecutionResult::executed();
        }

        $templateContext = $this->buildTemplateContext($state, $context->contactId);
        $payload         = $this->resolveMultilingualPayload($normalized, $context, $templateContext);

        $externalMessageId = $this->sender->send(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            payload: $payload,
        );

        if ( ! is_array($sentIds)) {
            $sentIds = [];
        }

        $sentIds[$nodeId] = $externalMessageId;

        $stateChanges = [
            SystemStateKeys::SENT_MESSAGES => $sentIds,
        ];

        if ($isInlineKeyboard) {
            if (null !== $normalized['timeout_seconds']) {
                $timeoutAt = now()->addSeconds(
                    $normalized['timeout_seconds']
                );
                $stateChanges[SystemStateKeys::SEND_MESSAGE_TIMEOUT_PREFIX
                              . ".{$nodeId}.at"] = $timeoutAt->toIso8601String();

                ResumeTimedOutSendMessageNodeJob::dispatch(
                    tenantId: $context->tenantId,
                    sessionId: $context->sessionId,
                    nodeId: $nodeId,
                    platform: $context->platform,
                )->delay($timeoutAt)->afterCommit();
            }

            // Register persistent button entries so the orchestrator can re-enter this branch
            // when remove_keyboard_after_press=false and the button is pressed after session ends.
            if ( ! $normalized['remove_keyboard_after_press'] && is_string($externalMessageId)) {
                $this->registerPersistentButtons($normalized, $state, $context, $externalMessageId);
            }

            return NodeExecutionResult::waiting(
                stateChanges: $stateChanges,
                metadata: [self::EXTERNAL_MESSAGE_ID_META => $externalMessageId],
            );
        }

        return NodeExecutionResult::executed(
            stateChanges: $stateChanges,
            metadata: [self::EXTERNAL_MESSAGE_ID_META => $externalMessageId],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array{
     *   content_type: SendMessageContentType,
     *   text?: array<string, mixed>|string|null,
     *   keyboard_mode: KeyboardMode|null,
     *   buttons: list<array<string, mixed>>,
     *   media_file_id: string|null,
     *   caption?: array<string, mixed>|string|null,
     *   timeout_seconds: int|null,
     *   save_to: string|null,
     *   remove_keyboard_after_press: bool
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

        // Legacy fields from pre-media-library nodes.
        // Try to recover media_file_id from the URL path (/media/files/{uuid}) before stripping.
        if (array_key_exists('media_url', $config) || array_key_exists('media_path', $config)) {
            $legacyUrl = is_string($config['media_url'] ?? null) ? $config['media_url'] : '';
            if (
                '' !== $legacyUrl
                && ! isset($config['media_file_id'])
                && preg_match('/\/media\/files\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $legacyUrl, $match)
            ) {
                $config['media_file_id'] = $match[1];
            }

            unset($config['media_url'], $config['media_path']);
        }

        if ($contentType->requiresMediaUrl() && ! is_string($config['media_file_id'] ?? null)) {
            throw new InvalidNodeConfigException("send_message {$contentType->value} requires media_file_id.");
        }

        foreach ($buttons as $button) {
            if ( ! is_array($button) || ! Uuid::isValid((string)($button['id'] ?? ''))) {
                throw new InvalidNodeConfigException('send_message buttons must contain stable UUID ids.');
            }
        }

        $saveTo = is_string($config['save_to'] ?? null) && '' !== $config['save_to']
            ? $config['save_to']
            : null;

        return [
            'content_type'  => $contentType,
            'text'          => $config['text'] ?? null,
            'keyboard_mode' => $keyboardMode,
            'buttons'       => $buttons,
            'media_file_id' => is_string(
                $config['media_file_id'] ?? null
            ) ? $config['media_file_id'] : null,
            'caption'         => $config['caption'] ?? null,
            'timeout_seconds' => is_numeric(
                $config['timeout_seconds'] ?? null
            ) ? (int)$config['timeout_seconds'] : null,
            'save_to'                     => $saveTo,
            'remove_keyboard_after_press' => true === ($config['remove_keyboard_after_press'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function resumeInlineKeyboard(
        array $config,
        array $state,
        NodeExecutionContext $context
    ): NodeExecutionResult {
        $responsePath   = SystemStateKeys::SEND_MESSAGE_RESPONSE_PREFIX . ".{$context->nodeId}";
        $storedHandle   = data_get($state, "{$responsePath}.handle");
        $storedUpdateId = data_get($state, "{$responsePath}.update_id");

        if (is_string($storedHandle) && $storedUpdateId === $context->incoming?->updateId) {
            return NodeExecutionResult::executed(sourceHandle: $storedHandle);
        }

        if (true === ($context->incoming?->payload['send_message_timeout'] ?? false)) {
            if ($config['remove_keyboard_after_press']) {
                $this->removeKeyboardIfSent($state, $context);
            }

            return NodeExecutionResult::executed(
                sourceHandle: self::NO_RESPONSE_HANDLE,
                stateChanges: [
                    "{$responsePath}.handle"    => self::NO_RESPONSE_HANDLE,
                    "{$responsePath}.update_id" => (string)$context->incoming?->updateId,
                ],
            );
        }

        $callbackPayload = CallbackDataCodec::decode($context->incoming?->text);

        if (($callbackPayload['session_id'] ?? null) !== $context->sessionId) {
            $this->sendWaitingHint($config, $state, $context);

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

            // Route by stable button ID — edges in the graph use button.id as their handle.
            $handle = (string)$button['id'];

            if ($config['remove_keyboard_after_press']) {
                $this->removeKeyboardIfSent($state, $context);
            }

            $stateChanges = [
                "{$responsePath}.handle"    => $handle,
                "{$responsePath}.update_id" => (string)$context->incoming?->updateId,
            ];

            // Write button value to flow state only when a save_to variable is configured.
            if (is_string($config['save_to'] ?? null)) {
                $stateChanges["flow.{$config['save_to']}"] = (string)($button['value'] ?? '');
            }

            return NodeExecutionResult::executed(
                sourceHandle: $handle,
                stateChanges: $stateChanges,
            );
        }

        return NodeExecutionResult::waiting();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function removeKeyboardIfSent(array $state, NodeExecutionContext $context): void
    {
        $sentIds           = data_get($state, SystemStateKeys::SENT_MESSAGES, []);
        $externalMessageId = is_array($sentIds) ? ($sentIds[$context->nodeId] ?? null) : null;

        if ( ! is_string($externalMessageId) || '' === $externalMessageId) {
            return;
        }

        $this->keyboardEditor->removeKeyboard(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            externalMessageId: $externalMessageId,
        );
    }

    /**
     * Sends a best-effort hint message when the user types text while the bot is waiting for a button press.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function sendWaitingHint(array $config, array $state, NodeExecutionContext $context): void
    {
        $timeoutAtStr = data_get($state, SystemStateKeys::SEND_MESSAGE_TIMEOUT_PREFIX . ".{$context->nodeId}.at");

        if (is_string($timeoutAtStr)) {
            $remaining = (int)now()->diffInSeconds(Carbon::parse($timeoutAtStr), false);
            $hint      = $remaining > 0
                ? "⚠️ Please press one of the buttons. Auto-cancel in {$remaining}s. Send /reset to cancel now."
                : '⚠️ Please press one of the buttons, or send /reset to cancel.';
        } else {
            $hint = '⚠️ Please press one of the buttons, or send /reset to cancel.';
        }

        try {
            $this->sender->send(
                tenantId: $context->tenantId,
                contactId: $context->contactId,
                sessionId: $context->sessionId,
                payload: [
                    'content_type'    => SendMessageContentType::Text->value,
                    'text'            => $hint,
                    'buttons'         => [],
                    'media_file_id'   => null,
                    'keyboard_mode'   => null,
                    'session_id'      => $context->sessionId,
                    'node_id'         => $context->nodeId . ':hint',
                    'idempotency_key' => 'hint:' . ($context->incoming?->updateId ?? uniqid()),
                ],
            );
        } catch (Throwable) {
            // Best-effort — never block the flow on a hint message failure.
        }
    }

    /**
     * Builds the template variable context by merging flow state with contact data.
     *
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function buildTemplateContext(array $state, string $contactId): array
    {
        try {
            $contact = Contact::query()->select(['id', 'language', 'meta', 'platform'])->find($contactId);
        } catch (Throwable) {
            $contact = null;
        }

        $meta = ($contact instanceof Contact && is_array($contact->meta)) ? $contact->meta : [];

        return array_merge($state, [
            'contact' => [
                'id'       => $contact instanceof Contact ? (string)$contact->getKey() : '',
                'language' => $contact instanceof Contact ? ($contact->language ?? '') : '',
                'name'     => $meta['name'] ?? $meta['first_name'] ?? '',
                'username' => $meta['username'] ?? '',
                'channel'  => $contact instanceof Contact ? ($contact->platform ?? '') : '',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $templateContext
     *
     * @return array<string, mixed>
     */
    private function resolveMultilingualPayload(
        array $config,
        NodeExecutionContext $context,
        array $templateContext
    ): array {
        if (isset($config['text']) && (is_array($config['text']) || is_string($config['text']))) {
            $resolved       = $this->translator->resolveField($config['text'], $context->resolvedLanguage);
            $config['text'] = is_string($resolved) ? $this->templates->resolve($resolved, $templateContext) : $resolved;
        }

        if (isset($config['caption']) && (is_array($config['caption']) || is_string($config['caption']))) {
            $resolved          = $this->translator->resolveField($config['caption'], $context->resolvedLanguage);
            $config['caption'] = is_string($resolved) ? $this->templates->resolve(
                $resolved,
                $templateContext
            ) : $resolved;
        }

        $buttons = $config['buttons'] ?? null;

        if (is_array($buttons)) {
            foreach ($buttons as $index => $button) {
                if ( ! is_array($button) || ! array_key_exists('label', $button)) {
                    continue;
                }

                if (is_array($button['label']) || is_string($button['label'])) {
                    $resolved = $this->translator->resolveField(
                        $button['label'],
                        $context->resolvedLanguage
                    );
                    $buttons[$index]['label'] = is_string($resolved) ? $this->templates->resolve(
                        $resolved,
                        $templateContext
                    ) : $resolved;
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
     * Registers each button in the keyboard as a persistent entry so the orchestrator
     * can route a future press even after this session ends.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function registerPersistentButtons(
        array $config,
        array $state,
        NodeExecutionContext $context,
        string $externalMessageId,
    ): void {
        $flowDefinitionId = data_get($state, FlowStateNamespace::SYSTEM . '.flow_definition_id');

        if ( ! is_string($flowDefinitionId) || '' === $flowDefinitionId) {
            return;
        }

        $buttonIds = array_column($config['buttons'], 'id');

        try {
            $this->persistentButtonRegistry->register(
                tenantId: $context->tenantId,
                contactId: $context->contactId,
                externalMessageId: $externalMessageId,
                originalSessionId: $context->sessionId,
                flowDefinitionId: $flowDefinitionId,
                nodeId: $context->nodeId,
                buttonIds: $buttonIds,
            );
        } catch (Throwable) {
            // Best-effort — never block flow execution on registry failure.
        }
    }
}
