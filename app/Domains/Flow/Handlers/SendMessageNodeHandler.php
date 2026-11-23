<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\AudienceBroadcasterInterface;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\NamesOwnRecipientInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Contracts\SendMessageTimeoutSchedulerInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\DTOs\AudienceBroadcastRequest;
use App\Domains\Flow\Enums\AudienceBroadcastOutcome;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Services\EventChainContext;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Flow\Support\AddresseeResolver;
use App\Domains\Flow\Support\CallbackDataCodec;
use Carbon\Carbon;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\ContactWriterInterface;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\KeyboardMode;
use Fapost\Foundation\Flow\Enums\StateNamespace;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Fapost\Support\Builder\Schema\Fields\ArrayField;
use Fapost\Support\Builder\Schema\Fields\NumberField;
use Fapost\Support\Builder\Schema\Fields\ObjectArrayField;
use Fapost\Support\Builder\Schema\Fields\ObjectField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\StatePickerField;
use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Fields\ToggleField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Sends a message to the contact of the run, or to the contact or audience its `addressee` names.
 *
 * An addressee (`{kind: "contact", contact: "<expression>"}`) lets a flow whose trigger gives no contact message a
 * person: the expression is rendered with the flow's engine and must give a contact id, or the node takes `error`
 * with `addressee_unresolved` and sends nothing. A node with an addressee never waits for an answer — no keyboard,
 * timeout or saved answer — since the answer would reach the addressee's own session, not this run.
 *
 * An audience addressee (`{kind: "tags", tags: [...]}` or `{kind: "segment", segment_id: "..."}`) starts a broadcast of
 * the flow's assistant through {@see AudienceBroadcasterInterface} and goes on without waiting for it: the text, every
 * language of it rendered at the node, is frozen into the broadcast, and Broadcasting picks each recipient's language.
 * The node execution's key makes a retried step get the same broadcast back; a node whose previous broadcast still runs,
 * an audience that is gone and a message without base-language text take `error` (`broadcast_running`,
 * `audience_missing`, `message_empty`). The broadcast's completion event is one level deeper than this run, so flows
 * that broadcast on each other's broadcasts stop at the chain limit.
 */
final class SendMessageNodeHandler extends AbstractVersionedHandler implements NamesOwnRecipientInterface, NodeConfigValidatorInterface
{
    final public const string TYPE = 'send_message';

    /** One contact, named by an expression; tags and segments are the other kinds ({@see AddresseeResolver}). */
    final public const string ADDRESSEE_KIND_CONTACT = AddresseeResolver::KIND_CONTACT;

    private const string BROADCAST_ID_META    = 'broadcast_id';
    private const string RECIPIENT_COUNT_META = 'recipient_count';

    /** Where `save_broadcast_to` may write: a flow variable. */
    private const string FLOW_PATH_PATTERN = '/^flow(\.\w+)+$/';

    /** A `{{contact…}}` or `{{module…}}` placeholder of the template engine. */
    private const string CONTACT_PLACEHOLDER_PATTERN = '/\{\{\s*(contact|module)(\.\w+)*\s*\}\}/';

    private const string EXTERNAL_MESSAGE_ID_META = 'external_message_id';
    private const string NO_RESPONSE_HANDLE       = 'no_response';

    public function __construct(
        private readonly MessageSenderInterface $sender,
        private readonly ContentTranslatorInterface $translator,
        private readonly TemplateRenderer $templates,
        private readonly InlineKeyboardEditorInterface $keyboardEditor,
        private readonly PersistentButtonRegistryInterface $persistentButtonRegistry,
        private readonly VariableResolverInterface $variableResolver,
        private readonly SendMessageTimeoutSchedulerInterface $timeoutScheduler,
        private readonly LoggerInterface $logger,
        private readonly AudienceBroadcasterInterface $broadcaster,
        private readonly EventChainContext $eventChain,
        private readonly FlowDefinitionRepositoryInterface $definitions,
        private readonly FlowDraftRepositoryInterface $drafts,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Conversation';
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
                                    ->namespaces([StateNamespace::Flow, StateNamespace::Contact])
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
                Section::make('addressee', 'Send to')
                    ->icon('user')
                    ->collapsed()
                    ->fields([
                        ObjectField::make('addressee')
                            ->label('Addressee')
                            ->help('Leave empty to send to the contact of the run. A flow started by a schedule, a webhook or an API call has no contact and needs one. A message to an addressee cannot wait for an answer; a message to tags or a segment is a text-only broadcast.')
                            ->fields([
                                SelectField::make('kind')
                                    ->label('Kind')
                                    ->options(AddresseeResolver::KINDS)
                                    ->default(self::ADDRESSEE_KIND_CONTACT),
                                TextField::make('contact')
                                    ->label('Contact id')
                                    ->placeholder('{{flow.contact_id}}')
                                    ->visibleWhen(['kind' => AddresseeResolver::KIND_CONTACT]),
                                ArrayField::make('tags')
                                    ->label('Tags')
                                    ->visibleWhen(['kind' => AddresseeResolver::KIND_TAGS]),
                                TextField::make('segment_id')
                                    ->label('Segment')
                                    ->visibleWhen(['kind' => AddresseeResolver::KIND_SEGMENT]),
                            ]),
                        TextField::make('broadcast_name')
                            ->label('Broadcast name'),
                        StatePickerField::make('save_broadcast_to')
                            ->label('Save broadcast to')
                            ->namespaces([StateNamespace::Flow])
                            ->placeholder('flow.broadcast'),
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

    public function namesRecipient(array $config): bool
    {
        return in_array(AddresseeResolver::parse($config)['kind'], AddresseeResolver::KINDS, true);
    }

    /**
     * An addressee must be of a known kind and must not wait for an answer; an audience must exist, and its message is
     * text that reads nothing of a contact.
     *
     * @param  array<string, mixed>  $node
     *
     * @return list<NodeConfigIssue>
     */
    public function validate(array $node): array
    {
        $config    = is_array($node['config'] ?? null) ? $node['config'] : [];
        $addressee = AddresseeResolver::parse($config);

        if (null === $addressee['kind']) {
            return [];
        }

        if (! in_array($addressee['kind'], AddresseeResolver::KINDS, true)) {
            return [NodeConfigIssue::error(
                'addressee_kind_unknown',
                "The addressee kind '{$addressee['kind']}' is not supported; use 'contact', 'tags' or 'segment'.",
                'addressee.kind',
            )];
        }

        $issues   = AddresseeResolver::isAudience($addressee['kind']) ? $this->audienceIssues($config, $addressee) : [];
        $answered = self::answerFields($config);

        if (AddresseeResolver::isAudience($addressee['kind'])) {
            // A keyboard is reported once, as not text.
            $answered = array_values(array_diff($answered, ['content_type']));
        }

        foreach ($answered as $field) {
            $issues[] = NodeConfigIssue::error(
                'addressee_cannot_wait',
                "A message to an addressee cannot wait for an answer: remove '{$field}' or the addressee.",
                $field,
            );
        }

        return $issues;
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $rawConfig = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $addressee = AddresseeResolver::parse($rawConfig);

        // Before the "already sent" check: a broadcast's replay guard is the node execution's key, not a state marker,
        // so every pass of a loop is its own request and a retried step gets its broadcast back.
        if (AddresseeResolver::isAudience($addressee['kind'])) {
            return $this->broadcastToAudience($rawConfig, $addressee, $state, $context);
        }

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

        $recipientId = $context->contactId;

        if (null !== $addressee['kind']) {
            $recipientId = $this->resolveAddressee($addressee, $state, $context);

            if (null === $recipientId) {
                return new NodeExecutionResult(
                    status: NodeExecutionStatus::Executed,
                    sourceHandle: 'error',
                    metadata: ['error' => 'The addressee gave no contact id.', 'error_type' => 'addressee_unresolved'],
                    errorMessage: 'addressee_unresolved: the addressee gave no contact id.',
                );
            }
        }

        $payload = $this->resolveMultilingualPayload($normalized, $context, $state);

        try {
            $externalMessageId = $this->sender->send(
                tenantId: $context->tenantId,
                contactId: $recipientId,
                sessionId: $context->sessionId,
                payload: $payload,
            );
        } catch (VolumeLimitReachedException $exception) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'error',
                metadata: ['error' => $exception->getMessage(), 'error_type' => 'limit_reached'],
                errorMessage: 'limit_reached: ' . $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $this->logger->warning('flow.send_message_failed', [
                'tenant_id'  => $context->tenantId,
                'session_id' => $context->sessionId,
                'node_id'    => $nodeId,
                'exception'  => $exception,
            ]);

            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'error',
                metadata: ['error' => $exception->getMessage(), 'error_type' => 'send_failure'],
                errorMessage: 'send_failure: ' . $exception->getMessage(),
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

                $this->timeoutScheduler->schedule(
                    tenantId: $context->tenantId,
                    sessionId: $context->sessionId,
                    nodeId: $nodeId,
                    platform: $context->platform,
                    timeoutAt: $timeoutAt,
                );
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
     * The config fields that make the node wait for, or save, an answer.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @return list<string>
     */
    private static function answerFields(array $config): array
    {
        $fields = [];

        if (SendMessageContentType::TextWithKeyboard->value === ($config['content_type'] ?? null)) {
            $fields[] = 'content_type';
        }

        if (is_numeric($config['timeout_seconds'] ?? null)) {
            $fields[] = 'timeout_seconds';
        }

        if (is_string($config['save_to'] ?? null) && '' !== $config['save_to']) {
            $fields[] = 'save_to';
        }

        $variable = $config['save_to_variable'] ?? null;

        if (is_array($variable) && is_string($variable['name'] ?? null) && '' !== $variable['name']) {
            $fields[] = 'save_to_variable';
        }

        return $fields;
    }

    /**
     * Whether a text, or any language of a localized one, has a `{{contact…}}` or `{{module…}}` placeholder.
     */
    private static function readsContact(mixed $value): bool
    {
        $texts = is_array($value) ? $value : [$value];

        foreach ($texts as $text) {
            if (is_string($text) && 1 === preg_match(self::CONTACT_PLACEHOLDER_PATTERN, $text)) {
                return true;
            }
        }

        return false;
    }

    private static function broadcastError(string $type, string $message): NodeExecutionResult
    {
        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'error',
            metadata: ['error' => $message, 'error_type' => $type],
            errorMessage: "{$type}: {$message}",
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

        if (null !== AddresseeResolver::parse($config)['kind'] && [] !== self::answerFields($config)) {
            throw new InvalidNodeConfigException('send_message with an addressee cannot wait for an answer.');
        }

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
     * The contact id the addressee's expression gives, rendered with the flow's engine; null when it gives none.
     * Never the contact of the run: a missing id must not message somebody else.
     *
     * @param  array{kind: string|null, contact: string, tags: list<string>, segment_id: string}  $addressee
     * @param  array<string, mixed>                                                               $state
     */
    private function resolveAddressee(array $addressee, array $state, NodeExecutionContext $context): ?string
    {
        if (self::ADDRESSEE_KIND_CONTACT !== $addressee['kind']) {
            return null;
        }

        $rendered = $this->templates->render($addressee['contact'], $context, $state);
        $id       = is_scalar($rendered) ? mb_trim((string) $rendered) : '';

        return Uuid::isValid($id) ? mb_strtolower($id) : null;
    }

    /**
     * What is wrong with an audience addressee's config: the audience, a message that is not text, a placeholder that
     * reads a contact (in a conversation it would be the run's contact, and every recipient would get their data), and
     * a `save_broadcast_to` that is not a flow variable.
     *
     * @param  array<array-key, mixed>                                                      $config
     * @param  array{kind: string|null, contact: string, tags: list<string>, segment_id: string}  $addressee
     *
     * @return list<NodeConfigIssue>
     */
    private function audienceIssues(array $config, array $addressee): array
    {
        $issues = [];

        if (AddresseeResolver::KIND_TAGS === $addressee['kind'] && ! AddresseeResolver::hasValidTags($config)) {
            $issues[] = NodeConfigIssue::error(
                'addressee_tags_required',
                'A message to tags needs at least one tag, each a non-empty text.',
                'addressee.tags',
            );
        }

        if (AddresseeResolver::KIND_SEGMENT === $addressee['kind']
            && ('' === $addressee['segment_id'] || ! $this->broadcaster->segmentExists($addressee['segment_id']))) {
            $issues[] = NodeConfigIssue::error(
                'addressee_segment_unknown',
                'A message to a segment needs a segment of this workspace.',
                'addressee.segment_id',
            );
        }

        if (SendMessageContentType::Text->value !== ($config['content_type'] ?? null)) {
            $issues[] = NodeConfigIssue::error(
                'addressee_broadcast_text_only',
                'A message to tags or a segment is sent as a broadcast and can only be text: no media and no buttons.',
                'content_type',
            );
        }

        foreach (['text', 'broadcast_name'] as $field) {
            if (self::readsContact($config[$field] ?? null)) {
                $issues[] = NodeConfigIssue::error(
                    'addressee_broadcast_contact_expression',
                    "A message to tags or a segment goes to many people and must not read a contact: remove the contact placeholders from '{$field}'.",
                    $field,
                );
            }
        }

        $saveTo = $config['save_broadcast_to'] ?? null;

        if (null !== $saveTo && '' !== $saveTo && ! (is_string($saveTo) && 1 === preg_match(self::FLOW_PATH_PATTERN, $saveTo))) {
            $issues[] = NodeConfigIssue::error(
                'save_broadcast_to_invalid',
                "'save_broadcast_to' must name a flow variable, such as 'flow.broadcast'.",
                'save_broadcast_to',
            );
        }

        return $issues;
    }

    /**
     * Starts the broadcast an audience addressee asks for and goes on: `default` with its id and estimated reach in the
     * metadata (and in `save_broadcast_to`), or `error` with the reason. Never throws for a refusal or a failure.
     *
     * @param  array<array-key, mixed>                                                      $config
     * @param  array{kind: string|null, contact: string, tags: list<string>, segment_id: string}  $addressee
     * @param  array<string, mixed>                                                          $state
     */
    private function broadcastToAudience(array $config, array $addressee, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        if (SendMessageContentType::Text->value !== ($config['content_type'] ?? null)) {
            throw new InvalidNodeConfigException('send_message to tags or a segment can only send text.');
        }

        try {
            $flowDefinitionId = data_get($state, StateNamespace::System->value . '.flow_definition_id');

            if (! is_string($flowDefinitionId) || '' === $flowDefinitionId) {
                return self::broadcastError('broadcast_failure', 'the run names no flow definition.');
            }

            $definition = $this->definitions->findById($flowDefinitionId);
            $draft      = $this->drafts->findByFlowId((string) $definition->flow_id);
            $message    = $this->renderBroadcastMessage($config['text'] ?? null, $state, $context);
            $name       = $this->broadcastName($config['broadcast_name'] ?? null, (string) $draft->name, $state, $context);

            $result = $this->broadcaster->start(new AudienceBroadcastRequest(
                tenantId: $context->tenantId,
                assistantId: (string) $draft->assistant_id,
                name: $name,
                audience: AddresseeResolver::KIND_SEGMENT === $addressee['kind']
                    ? AudienceBroadcastRequest::AUDIENCE_SEGMENT
                    : AudienceBroadcastRequest::AUDIENCE_TAGS,
                tags: $addressee['tags'],
                segmentId: '' === $addressee['segment_id'] ? null : $addressee['segment_id'],
                message: $message,
                flowId: (string) $definition->flow_id,
                sessionId: $context->sessionId,
                nodeId: $context->nodeId,
                originKey: hash('sha256', "{$context->idempotencyKey}:{$context->nodeId}"),
                chainDepth: $this->eventChain->current() + 1,
            ));
        } catch (Throwable $exception) {
            $this->logger->warning('flow.send_message_broadcast_failed', [
                'tenant_id'  => $context->tenantId,
                'session_id' => $context->sessionId,
                'node_id'    => $context->nodeId,
                'exception'  => $exception,
            ]);

            return self::broadcastError('broadcast_failure', $exception->getMessage());
        }

        if (! $result->outcome->startedBroadcast()) {
            return self::broadcastError($result->outcome->value, match ($result->outcome) {
                AudienceBroadcastOutcome::Busy            => 'the broadcast this node started before is still running.',
                AudienceBroadcastOutcome::AudienceMissing => 'the tags or the segment the node names are gone.',
                default                                   => 'the message has no text in the base language.',
            });
        }

        $output = [
            self::BROADCAST_ID_META    => $result->broadcastId,
            self::RECIPIENT_COUNT_META => $result->recipientCount,
        ];

        $saveTo       = $config['save_broadcast_to'] ?? null;
        $stateChanges = is_string($saveTo) && 1 === preg_match(self::FLOW_PATH_PATTERN, $saveTo) ? [$saveTo => $output] : [];

        return NodeExecutionResult::executed(
            stateChanges: $stateChanges,
            metadata: [...$output, 'broadcast_outcome' => $result->outcome->value],
        );
    }

    /**
     * Every language of the text, rendered with the flow's engine now: the broadcast keeps it as it is, and Broadcasting
     * chooses each recipient's language. A plain string is the base language's text.
     *
     * @param  array<string, mixed>  $state
     *
     * @return array<string, string>|string
     */
    private function renderBroadcastMessage(mixed $text, array $state, NodeExecutionContext $context): array|string
    {
        if (is_string($text)) {
            return $this->renderToString($text, $state, $context);
        }

        if (! is_array($text)) {
            return [];
        }

        $rendered = [];

        foreach ($text as $language => $value) {
            if (is_string($language) && is_string($value)) {
                $rendered[$language] = $this->renderToString($value, $state, $context);
            }
        }

        return $rendered;
    }

    /**
     * The broadcast's name on the console: the node's, rendered, or "<flow name> — <date and time>".
     *
     * @param  array<string, mixed>  $state
     */
    private function broadcastName(mixed $configured, string $flowName, array $state, NodeExecutionContext $context): string
    {
        $name = is_string($configured) ? mb_trim($this->renderToString($configured, $state, $context)) : '';

        return '' !== $name ? $name : "{$flowName} — " . Carbon::now()->format('Y-m-d H:i');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function renderToString(string $template, array $state, NodeExecutionContext $context): string
    {
        $rendered = $this->templates->render($template, $context, $state);

        return is_scalar($rendered) ? (string) $rendered : '';
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
                    'idempotency_key' => 'hint:' . ($context->incoming?->updateId ?? uniqid('', true)),
                ],
            );
        } catch (Throwable) {
            // Best-effort — never block the flow on a hint message failure.
        }
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
        $flowDefinitionId = data_get($state, StateNamespace::System->value . '.flow_definition_id');

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
