<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\FlowStateNamespace;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Jobs\Flow\ResumeTimedOutSendMessageNodeJob;
use Carbon\Carbon;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\Flow\Contracts\ContactWriterInterface;
use FAPost\Foundation\Flow\Enums\KeyboardMode;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\NumberField;
use FAPost\Support\Builder\Schema\Fields\ObjectArrayField;
use FAPost\Support\Builder\Schema\Fields\ObjectField;
use FAPost\Support\Builder\Schema\Fields\SelectField;
use FAPost\Support\Builder\Schema\Fields\StatePickerField;
use FAPost\Support\Builder\Schema\Fields\TextareaField;
use FAPost\Support\Builder\Schema\Fields\TextField;
use FAPost\Support\Builder\Schema\Fields\ToggleField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;
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
        private readonly TemplateRenderer $templates,
        private readonly InlineKeyboardEditorInterface $keyboardEditor,
        private readonly PersistentButtonRegistryInterface $persistentButtonRegistry,
        private readonly VariableResolverInterface $variableResolver,
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
        return Schema::make()
            ->required(['content_type'])
            ->defaultConfig([
                'content_type' => SendMessageContentType::Text->value,
                'text'         => '',
            ])
            ->section(
                Section::make('content', 'Content')
                    ->icon('chat-bubble-left-right')
                    ->fields([
                        SelectField::make('content_type')
                            ->label('Content type')
                            ->required()
                            ->options(SendMessageContentType::cases())
                            ->default(SendMessageContentType::Text),
                        TextareaField::make('text')
                            ->label('Text')
                            ->default(''),
                        TextField::make('media_file_id')
                            ->label('Media file')
                            ->visibleWhen([
                                [
                                    'field' => 'content_type',
                                    'op'    => 'in',
                                    'value' => [
                                        SendMessageContentType::Image,
                                        SendMessageContentType::Document,
                                        SendMessageContentType::Video,
                                        SendMessageContentType::Voice,
                                    ],
                                ],
                            ]),
                        TextareaField::make('caption')
                            ->label('Caption')
                            ->visibleWhen([
                                [
                                    'field' => 'content_type',
                                    'op'    => 'in',
                                    'value' => [
                                        SendMessageContentType::Image,
                                        SendMessageContentType::Document,
                                        SendMessageContentType::Video,
                                    ],
                                ],
                            ]),
                    ]),
            )
            ->section(
                Section::make('keyboard', 'Keyboard')
                    ->icon('rectangle-group')
                    ->fields([
                        SelectField::make('keyboard_mode')
                            ->label('Keyboard mode')
                            ->options(KeyboardMode::cases())
                            ->default(KeyboardMode::Inline)
                            ->visibleWhen(['content_type' => SendMessageContentType::TextWithKeyboard]),
                        ObjectArrayField::make('buttons')
                            ->label('Buttons')
                            ->itemLabel('{label}')
                            ->itemFields([
                                TextField::make('id')->label('ID')->required(),
                                TextareaField::make('label')->label('Label')->required(),
                                TextareaField::make('value')->label('Value'),
                            ])
                            ->visibleWhen(['content_type' => SendMessageContentType::TextWithKeyboard]),
                        ObjectField::make('dynamic_buttons')
                            ->label('Dynamic buttons')
                            ->fields([
                                StatePickerField::make('source')
                                    ->label('Source')
                                    ->required()
                                    ->placeholder('flow.items'),
                                NumberField::make('max_per_row')
                                    ->label('Max per row')
                                    ->default(2)
                                    ->min(1)
                                    ->max(8),
                            ])
                            ->visibleWhen(['content_type' => SendMessageContentType::TextWithKeyboard]),
                    ]),
            )
            ->section(
                Section::make('response', 'Response')
                    ->icon('arrow-down-tray')
                    ->collapsed()
                    ->fields([
                        StatePickerField::make('save_to')
                            ->label('Save answer to')
                            ->placeholder('flow.button_answer'),
                        ObjectField::make('save_to_variable')
                            ->label('Save answer variable')
                            ->fields([
                                TextField::make('name')->label('Name')->required(),
                                SelectField::make('type')
                                    ->label('Type')
                                    ->options(['text', 'number', 'confirm'])
                                    ->default('text'),
                                SelectField::make('storage')
                                    ->label('Storage')
                                    ->options(['session', 'contact'])
                                    ->default('session'),
                                TextField::make('group')->label('Group'),
                            ]),
                        NumberField::make('timeout_seconds')
                            ->label('Timeout (seconds)')
                            ->min(1),
                        ToggleField::make('remove_keyboard_after_press')
                            ->label('Remove keyboard after press')
                            ->default(true),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $sentIds          = data_get($state, SystemStateKeys::SENT_MESSAGES, []);
        $nodeId           = $context->nodeId;
        $config           = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $normalized       = $this->normalizeConfig($config);
        $isInlineKeyboard = SendMessageContentType::TextWithKeyboard === $normalized['content_type']
                            && KeyboardMode::Inline === $normalized['keyboard_mode'];
        $hasDynamic = null !== $normalized['dynamic_buttons'];

        $alreadySent = is_array($sentIds) && array_key_exists($nodeId, $sentIds);

        if ($isInlineKeyboard && $alreadySent && null !== $context->incoming) {
            if ($hasDynamic) {
                // Reload generated buttons persisted at send time.
                $normalized['buttons'] = data_get(
                    $state,
                    SystemStateKeys::SEND_MESSAGE_DYNAMIC_BUTTONS . ".{$nodeId}",
                    [],
                );
            }

            return $this->resumeInlineKeyboard($normalized, $state, $context);
        }

        if ($alreadySent) {
            return $isInlineKeyboard
                ? NodeExecutionResult::waiting()
                : NodeExecutionResult::executed();
        }

        // Generate dynamic buttons from state collection before building the payload.
        if ($hasDynamic) {
            $normalized['buttons'] = $this->generateDynamicButtons(
                $normalized['dynamic_buttons'],
                $state,
                $context,
            );
        }

        $templateContext = $this->buildTemplateContext($state, $context->contactId);
        $payload         = $this->resolveMultilingualPayload($normalized, $context, $templateContext);

        try {
            $externalMessageId = $this->sender->send(
                tenantId: $context->tenantId,
                contactId: $context->contactId,
                sessionId: $context->sessionId,
                payload: $payload,
            );
        } catch (Throwable $exception) {
            return NodeExecutionResult::executed(
                sourceHandle: 'error',
                metadata: ['error' => $exception->getMessage(), 'error_type' => 'send_failure'],
            );
        }

        if (! is_array($sentIds)) {
            $sentIds = [];
        }

        $sentIds[$nodeId] = $externalMessageId;

        $stateChanges = [
            SystemStateKeys::SENT_MESSAGES => $sentIds,
        ];

        if ($isInlineKeyboard) {
            if ($hasDynamic) {
                // Persist generated buttons so resume can match them without re-reading state collection.
                $stateChanges[SystemStateKeys::SEND_MESSAGE_DYNAMIC_BUTTONS . ".{$nodeId}"] = $normalized['buttons'];
            }

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
            if (! $normalized['remove_keyboard_after_press'] && is_string($externalMessageId)) {
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
     *   dynamic_buttons: array{source: string, max_per_row: int, save_item_to: array<string,mixed>|null}|null,
     *   media_file_id: string|null,
     *   caption?: array<string, mixed>|string|null,
     *   timeout_seconds: int|null,
     *   save_to: string|null,
     *   save_to_variable: array<string, mixed>|null,
     *   remove_keyboard_after_press: bool
     * }
     */
    private function normalizeConfig(array $config): array
    {
        $rawContentType = $config['content_type'] ?? null;
        $contentType    = is_string($rawContentType)
            ? SendMessageContentType::tryFrom($rawContentType)
            : null;

        if (! $contentType instanceof SendMessageContentType) {
            throw new InvalidNodeConfigException('send_message node requires a supported content_type.');
        }

        $keyboardMode = null;
        if (SendMessageContentType::TextWithKeyboard === $contentType) {
            $rawKeyboardMode = $config['keyboard_mode'] ?? null;
            $keyboardMode    = is_string($rawKeyboardMode)
                ? KeyboardMode::tryFrom($rawKeyboardMode)
                : null;

            if (! $keyboardMode instanceof KeyboardMode) {
                throw new InvalidNodeConfigException('send_message text_with_keyboard requires keyboard_mode.');
            }
        }

        $buttons = is_array($config['buttons'] ?? null) ? array_values($config['buttons']) : [];

        $dynamicButtons = null;
        if (is_array($config['dynamic_buttons'] ?? null)) {
            $source = $config['dynamic_buttons']['source'] ?? null;
            if (! is_string($source) || '' === $source) {
                throw new InvalidNodeConfigException('send_message dynamic_buttons requires a non-empty source path.');
            }
            $dynamicButtons = [
                'source'      => $source,
                'max_per_row' => is_int($config['dynamic_buttons']['max_per_row'] ?? null)
                    ? max(1, $config['dynamic_buttons']['max_per_row'])
                    : 2,
                'save_item_to' => is_array($config['dynamic_buttons']['save_item_to'] ?? null)
                    ? $config['dynamic_buttons']['save_item_to']
                    : null,
            ];
        }

        if (SendMessageContentType::TextWithKeyboard === $contentType && [] === $buttons && null === $dynamicButtons) {
            throw new InvalidNodeConfigException('send_message text_with_keyboard requires at least one button or dynamic_buttons.');
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
            if (! is_array($button) || ! Uuid::isValid((string)($button['id'] ?? ''))) {
                throw new InvalidNodeConfigException('send_message buttons must contain stable UUID ids.');
            }
        }

        $saveTo = is_string($config['save_to'] ?? null) && '' !== $config['save_to']
            ? $config['save_to']
            : null;

        $saveToVariable = is_array($config['save_to_variable'] ?? null)
            ? $config['save_to_variable']
            : null;

        return [
            'content_type'    => $contentType,
            'text'            => $config['text'] ?? null,
            'keyboard_mode'   => $keyboardMode,
            'buttons'         => $buttons,
            'dynamic_buttons' => $dynamicButtons,
            'media_file_id'   => is_string(
                $config['media_file_id'] ?? null
            ) ? $config['media_file_id'] : null,
            'caption'         => $config['caption'] ?? null,
            'timeout_seconds' => is_numeric(
                $config['timeout_seconds'] ?? null
            ) ? (int)$config['timeout_seconds'] : null,
            'save_to'                     => $saveTo,
            'save_to_variable'            => $saveToVariable,
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
        if (! is_string($buttonId) || '' === $buttonId) {
            return NodeExecutionResult::waiting();
        }

        foreach ($config['buttons'] as $button) {
            if (($button['id'] ?? null) !== $buttonId) {
                continue;
            }

            // Dynamic keyboards always route through the `default` edge; static keyboards
            // use the stable button UUID as the handle so each button maps to its own edge.
            $handle = null !== ($config['dynamic_buttons'] ?? null)
                ? 'default'
                : (string)$button['id'];

            if ($config['remove_keyboard_after_press']) {
                $this->removeKeyboardIfSent($state, $context);
            }

            $stateChanges = [
                "{$responsePath}.handle"    => $handle,
                "{$responsePath}.update_id" => (string)$context->incoming?->updateId,
            ];

            $dynamicConfig = $config['dynamic_buttons'] ?? null;

            if (null !== $dynamicConfig) {
                // Dynamic keyboard: save the full item object to the configured target.
                $saveItemTo = $dynamicConfig['save_item_to'] ?? null;

                if (is_array($saveItemTo)) {
                    $variable = Variable::tryFromArray($saveItemTo);

                    if ($variable instanceof Variable) {
                        $path = $this->variableResolver->resolveTargetPath($variable);
                        $item = $button['item'] ?? [];

                        if (VariableStorage::Contact === $variable->storage) {
                            $writer = $context->contactWriter;

                            if ($writer instanceof ContactWriterInterface) {
                                $writer->write($path, $item);
                            }
                        } else {
                            $stateChanges[$path] = $item;
                        }
                    }
                }
            } else {
                // Static keyboard: save the scalar button value to the configured target.
                $variable = $this->resolveSaveTarget($config);
                $value    = (string)($button['value'] ?? '');

                if ($variable instanceof Variable) {
                    $path = $this->variableResolver->resolveTargetPath($variable);

                    if (VariableStorage::Contact === $variable->storage) {
                        $writer = $context->contactWriter;

                        if ($writer instanceof ContactWriterInterface) {
                            $writer->write($path, $value);
                        }
                    } else {
                        $stateChanges[$path] = $value;
                    }
                }
            }

            return NodeExecutionResult::executed(
                sourceHandle: $handle,
                stateChanges: $stateChanges,
            );
        }

        return NodeExecutionResult::waiting();
    }

    /**
     * Resolve the configured "save button value" target to a {@see Variable}.
     *
     * New {@code save_to_variable} payload (UI shape) wins over legacy
     * {@code save_to} string; absent values yield {@code null}.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveSaveTarget(array $config): ?Variable
    {
        if (is_array($config['save_to_variable'] ?? null)) {
            return Variable::tryFromArray($config['save_to_variable']);
        }

        $saveTo = $config['save_to'] ?? null;

        if (! is_string($saveTo) || '' === $saveTo) {
            return null;
        }

        return $this->variableResolver->fromLegacyPath($saveTo);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function removeKeyboardIfSent(array $state, NodeExecutionContext $context): void
    {
        $sentIds           = data_get($state, SystemStateKeys::SENT_MESSAGES, []);
        $externalMessageId = is_array($sentIds) ? ($sentIds[$context->nodeId] ?? null) : null;

        if (! is_string($externalMessageId) || '' === $externalMessageId) {
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
        $lang         = $context->resolvedLanguage;

        if (is_string($timeoutAtStr)) {
            $remaining = (int)now()->diffInSeconds(Carbon::parse($timeoutAtStr), false);
            $hint      = $remaining > 0
                ? str_replace(':seconds', (string)$remaining, $this->translator->translate('errors.waiting_for_button_timed', $lang))
                : $this->translator->translate('errors.waiting_for_button', $lang);
        } else {
            $hint = $this->translator->translate('errors.waiting_for_button', $lang);
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
            $config['text'] = is_string($resolved) ? $this->templates->render($resolved, $context, $templateContext) : $resolved;
        }

        if (isset($config['caption']) && (is_array($config['caption']) || is_string($config['caption']))) {
            $resolved          = $this->translator->resolveField($config['caption'], $context->resolvedLanguage);
            $config['caption'] = is_string($resolved) ? $this->templates->render(
                $resolved,
                $context,
                $templateContext
            ) : $resolved;
        }

        $buttons = $config['buttons'] ?? null;

        if (is_array($buttons)) {
            foreach ($buttons as $index => $button) {
                if (! is_array($button) || ! array_key_exists('label', $button)) {
                    continue;
                }

                if (is_array($button['label']) || is_string($button['label'])) {
                    $resolved = $this->translator->resolveField(
                        $button['label'],
                        $context->resolvedLanguage
                    );
                    $buttons[$index]['label'] = is_string($resolved) ? $this->templates->render(
                        $resolved,
                        $context,
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
     * Generates inline keyboard buttons at runtime from a state collection. Items must
     * follow the {@see DynamicKeyboardItem} contract — each must have a non-empty `label`
     * string field. Items missing `label` are silently skipped. The full item object is
     * stored alongside the button so it can be saved on press via {@code save_item_to}.
     * Button IDs are derived deterministically from (nodeId, index) so they survive job
     * retries without regeneration.
     *
     * @param  array{source: string, max_per_row: int, save_item_to: array<string,mixed>|null}  $dynamicConfig
     * @param  array<string, mixed>  $state
     *
     * @return list<array<string, mixed>>
     */
    private function generateDynamicButtons(
        array $dynamicConfig,
        array $state,
        NodeExecutionContext $context,
    ): array {
        $source    = $dynamicConfig['source'];
        $maxPerRow = $dynamicConfig['max_per_row'];

        $collection = data_get($state, $source);

        if (! is_array($collection) || [] === $collection) {
            return [];
        }

        $buttons     = [];
        $buttonIndex = 0;

        foreach (array_values($collection) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = is_string($item['label'] ?? null) ? $item['label'] : '';

            if ('' === $label) {
                continue;
            }

            // Stable deterministic ID: consistent across job retries for the same (node, position).
            $id = Uuid::uuid5(Uuid::NAMESPACE_OID, "{$context->nodeId}:{$buttonIndex}")->toString();

            $buttons[] = [
                'id'    => $id,
                'label' => $label,
                'row'   => (int)floor($buttonIndex / $maxPerRow),
                'item'  => $item,
            ];

            $buttonIndex++;
        }

        return $buttons;
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

        if (! is_string($flowDefinitionId) || '' === $flowDefinitionId) {
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
