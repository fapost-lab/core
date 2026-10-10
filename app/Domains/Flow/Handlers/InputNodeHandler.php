<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Enums\InputExpectedType;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Flow\State\Variables\VariableType;
use App\Domains\Flow\Validation\InputValidatorInterface;
use App\Domains\Flow\Validation\ValidationResult;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\ContactWriterInterface;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\StatePickerField;
use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use RuntimeException;
use Throwable;

final class InputNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'input';

    private const string RECEIVED_META       = 'received';
    private const string INVALID_HANDLE      = 'invalid';
    private const string DEFAULT_RETRY_LIMIT = '3';

    public function __construct(
        private readonly MediaIngestorInterface $mediaIngestor,
        private readonly MediaServiceInterface $mediaService,
        private readonly VariableResolverInterface $variableResolver,
        private readonly MessageSenderInterface $sender,
        private readonly ContentTranslatorInterface $translator,
        private readonly InputValidatorInterface $validator,
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
            ->section(
                Section::make('storage', 'Storage')
                    ->icon('archive-box')
                    ->fields([
                        StatePickerField::make('save_to')
                            ->label('Save to')
                            ->placeholder('flow.user_input'),
                    ]),
            )
            ->section(
                Section::make('validation', 'Validation')
                    ->icon('check-circle')
                    ->fields([
                        SelectField::make('expected_type')
                            ->label('Expected input')
                            ->options(InputExpectedType::cases())
                            ->default(InputExpectedType::Text),
                        TextField::make('retry_limit')
                            ->label('Retry limit')
                            ->default(self::DEFAULT_RETRY_LIMIT),
                        TextareaField::make('on_invalid_message')
                            ->label('On invalid message'),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config       = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $expectedType = $this->resolveExpectedType($config);
        $variable     = $this->resolveVariable($config);

        // Media path stays in its own ingest pipeline — the validator
        // only handles textual / select / platform-native input.
        if ($expectedType->isMedia()) {
            $promptResult = $this->sendPromptIfNeeded($config, $state, $context, withKeyboard: false);
            if (null !== $promptResult) {
                return $promptResult;
            }

            return $this->handleMediaInput($expectedType, $variable, $state, $context);
        }

        // Platform-native types send a ReplyKeyboard with a special native button.
        $withNativeButton = $expectedType->isPlatformNative();

        $promptResult = $withNativeButton
            ? $this->sendNativeButtonPromptIfNeeded($config, $state, $context, $expectedType)
            : $this->sendPromptIfNeeded($config, $state, $context, withKeyboard: $expectedType->isSelect());

        if (null !== $promptResult) {
            return $promptResult;
        }

        if (null === $context->incoming) {
            return NodeExecutionResult::waiting();
        }

        $rules = is_array($config['validation'] ?? null) ? $config['validation'] : [];

        // Phone validation candidates come from the assistant's served countries
        // (the node's own `country` field, when set, narrows to one of them).
        if (InputExpectedType::Phone === $expectedType) {
            $rules['countries'] = $this->resolveAssistantCountries($context);
        }

        $result = $this->validator->validate($expectedType, $context->incoming, $rules, $nodeConfig);

        if ($result->valid) {
            return $this->emitSuccess($variable, $result->value, $state, $context);
        }

        return $this->handleInvalid($result, $config, $state, $context);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveExpectedType(array $config): InputExpectedType
    {
        $raw = is_string($config['expected_type'] ?? null) ? $config['expected_type'] : null;

        return (null !== $raw ? InputExpectedType::tryFrom($raw) : null) ?? InputExpectedType::Text;
    }

    /**
     * Send the configured prompt before parking the session. Idempotent via
     * {@see SystemStateKeys::SENT_MESSAGES}: once dispatched, repeat passes
     * skip the send. For select/confirm types the prompt is delivered with
     * an inline keyboard built from `config.buttons` — the same payload
     * shape send_message uses, so {@see \App\Domains\Flow\Services\FlowMessageSender}
     * routes it through the existing keyboard pipeline.
     *
     * Returns the result the engine should propagate (Waiting after a send)
     * or `null` to mean "carry on with input handling".
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function sendPromptIfNeeded(
        array $config,
        array $state,
        NodeExecutionContext $context,
        bool $withKeyboard,
    ): ?NodeExecutionResult {
        $promptRaw = $config['prompt'] ?? null;
        $resolved  = (is_string($promptRaw) || is_array($promptRaw))
            ? $this->translator->resolveField($promptRaw, $context->resolvedLanguage)
            : '';
        $text = mb_trim($resolved);

        $sentIds = data_get($state, SystemStateKeys::SENT_MESSAGES, []);
        if (is_array($sentIds) && array_key_exists($context->nodeId, $sentIds)) {
            return null;
        }

        // Select prompts must always go out (the user has no way to respond without the keyboard).
        // Plain prompts may be skipped when empty.
        if (! $withKeyboard && '' === $text) {
            return null;
        }

        $payload = $withKeyboard
            ? [
                'content_type'  => 'text_with_keyboard',
                'text'          => $text,
                'buttons'       => $this->resolveButtons($config, $context->resolvedLanguage),
                'keyboard_mode' => 'inline',
                'session_id'    => $context->sessionId,
            ]
            : [
                'content_type' => 'text',
                'text'         => $text,
            ];

        $externalMessageId = $this->sender->send(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            payload: $payload,
        );

        $nextSent                   = is_array($sentIds) ? $sentIds : [];
        $nextSent[$context->nodeId] = $externalMessageId;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            stateChanges: [
                SystemStateKeys::SENT_MESSAGES => $nextSent,
            ],
        );
    }

    /**
     * Send a prompt with a Telegram-native sharing button (request_contact /
     * request_location) via a one-time ReplyKeyboard. The button label is taken
     * from `config.request_button_label` (localizable). Idempotent: skipped on
     * subsequent passes once the message has been recorded in SENT_MESSAGES.
     *
     * A non-empty prompt text from `config.prompt` IS required — the native
     * button itself carries no instruction, so the prompt is the only context
     * the user sees before pressing.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function sendNativeButtonPromptIfNeeded(
        array $config,
        array $state,
        NodeExecutionContext $context,
        InputExpectedType $expectedType,
    ): ?NodeExecutionResult {
        $sentIds = data_get($state, SystemStateKeys::SENT_MESSAGES, []);
        if (is_array($sentIds) && array_key_exists($context->nodeId, $sentIds)) {
            return null;
        }

        $promptRaw = $config['prompt'] ?? null;
        $text      = (is_string($promptRaw) || is_array($promptRaw))
            ? mb_trim($this->translator->resolveField($promptRaw, $context->resolvedLanguage))
            : '';

        $buttonLabelRaw = $config['request_button_label'] ?? null;
        $buttonLabel    = (is_string($buttonLabelRaw) || is_array($buttonLabelRaw))
            ? mb_trim($this->translator->resolveField($buttonLabelRaw, $context->resolvedLanguage))
            : '';

        // Fall back to type-based defaults when label is blank.
        if ('' === $buttonLabel) {
            $buttonLabel = match ($expectedType) {
                InputExpectedType::Contact  => '📱 Share contact',
                InputExpectedType::Location => '📍 Share location',
                default                     => 'Share',
            };
        }

        $specialField = match ($expectedType) {
            InputExpectedType::Contact  => 'request_contact',
            InputExpectedType::Location => 'request_location',
            default                     => null,
        };

        $externalMessageId = $this->sender->send(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            payload: [
                'content_type' => 'text_with_keyboard',
                'text'         => '' !== $text ? $text : $buttonLabel,
                'buttons'      => [
                    [
                        'id'      => 'native-button',
                        'type'    => 'reply',
                        'label'   => $buttonLabel,
                        'special' => $specialField,
                        'row'     => 0,
                        'order'   => 0,
                    ],
                ],
                'keyboard_mode' => 'reply',
                'session_id'    => $context->sessionId,
            ],
        );

        $nextSent                   = is_array($sentIds) ? $sentIds : [];
        $nextSent[$context->nodeId] = $externalMessageId;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            stateChanges: [SystemStateKeys::SENT_MESSAGES => $nextSent],
        );
    }

    /**
     * Resolve each button's localized label so the sender receives a
     * language-flattened keyboard.
     *
     * @param  array<string, mixed>  $config
     *
     * @return list<array<string, mixed>>
     */
    private function resolveButtons(array $config, string $language): array
    {
        $buttons = is_array($config['buttons'] ?? null) ? array_values($config['buttons']) : [];

        return array_map(
            function (mixed $button) use ($language): array {
                $btn          = is_array($button) ? $button : [];
                $labelRaw     = $btn['label'] ?? null;
                $btn['label'] = (is_string($labelRaw) || is_array($labelRaw))
                    ? $this->translator->resolveField($labelRaw, $language)
                    : '';
                $btn['type'] = is_string($btn['type'] ?? null) ? $btn['type'] : 'callback';

                return $btn;
            },
            $buttons,
        );
    }

    /**
     * Successful validation: write the (parsed) value to the configured
     * variable and clear the per-node retry counter if any attempts were made.
     *
     * @param  array<string, mixed>  $state
     */
    private function emitSuccess(?Variable $variable, mixed $value, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $stateChanges = $this->persistValue($variable, $value, $state, $context);

        // Only emit the retry-counter reset when there was actually something to
        // reset — keeps the happy-path stateChanges minimal and avoids polluting
        // session state with null entries for nodes that never failed.
        if ((int)data_get($state, $this->retryPath($context), 0) > 0) {
            $stateChanges[$this->retryPath($context)] = null;
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: $stateChanges,
            metadata: [self::RECEIVED_META => $value],
        );
    }

    /**
     * Validation failed. Increment the retry counter; if we have budget left,
     * re-send the on_invalid_message hint (if configured) and park. Once the
     * counter reaches the configured retry_limit, drop the session through
     * the `invalid` handle so the flow author can branch into recovery.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function handleInvalid(
        ValidationResult $result,
        array $config,
        array $state,
        NodeExecutionContext $context,
    ): NodeExecutionResult {
        $retryLimit   = max(0, (int)($config['retry_limit'] ?? self::DEFAULT_RETRY_LIMIT));
        $retryPath    = $this->retryPath($context);
        $currentCount = (int)data_get($state, $retryPath, 0);
        $nextCount    = $currentCount + 1;

        if ($nextCount > $retryLimit) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::INVALID_HANDLE,
                stateChanges: [$retryPath => null],
                metadata: ['error_key' => $result->errorKey, 'retry_count' => $currentCount],
            );
        }

        $this->sendInvalidHintIfConfigured($config, $context);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            stateChanges: [$retryPath => $nextCount],
            metadata: ['error_key' => $result->errorKey, 'retry_count' => $nextCount],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sendInvalidHintIfConfigured(array $config, NodeExecutionContext $context): void
    {
        $hintRaw = $config['on_invalid_message'] ?? null;

        if (! is_string($hintRaw) && ! is_array($hintRaw)) {
            return;
        }

        $resolved = $this->translator->resolveField($hintRaw, $context->resolvedLanguage);

        if ('' === mb_trim($resolved)) {
            return;
        }

        $this->sender->send(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            payload: [
                'content_type' => 'text',
                'text'         => $resolved,
            ],
        );
    }

    private function retryPath(NodeExecutionContext $context): string
    {
        return SystemStateKeys::INPUT_RETRY_PREFIX . ".{$context->nodeId}.retry_count";
    }

    /**
     * Handle a media-typed input by ingesting every attachment on the incoming message
     * through {@see MediaIngestorInterface} and storing the resulting media descriptors
     * as a list under the configured save_to key. Multi-file messages produce multi-element
     * lists — single-file messages produce one-element lists for a uniform downstream shape.
     */
    private function handleMediaInput(
        InputExpectedType $expectedType,
        ?Variable $variable,
        array $state,
        NodeExecutionContext $context
    ): NodeExecutionResult {
        $incoming = $context->incoming;

        if (null === $incoming || [] === $incoming->media) {
            return new NodeExecutionResult(status: NodeExecutionStatus::Waiting);
        }

        $accepted = $this->filterAcceptedMedia($incoming->media, $expectedType);

        if ([] === $accepted) {
            return new NodeExecutionResult(status: NodeExecutionStatus::Waiting);
        }

        $channel = $this->resolveChannel($context);
        $folder  = $this->mediaService->findOrCreateInboxFolder();

        $stored = [];

        foreach ($accepted as $media) {
            try {
                $mediaFile = $this->mediaIngestor->ingestFromChannel(
                    channel: $channel,
                    providerFileId: $media->providerFileId,
                    folder: $folder,
                );
            } catch (StorageLimitReachedException) {
                // No retries and no hint: asking the contact again cannot make room. The author's
                // `invalid` branch decides what the contact is told.
                return new NodeExecutionResult(
                    status: NodeExecutionStatus::Executed,
                    sourceHandle: self::INVALID_HANDLE,
                    stateChanges: [$this->retryPath($context) => null],
                    metadata: ['error_key' => StorageLimitReachedException::ERROR_KEY],
                );
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    sprintf(
                        'Input node failed to ingest media [%s]: %s',
                        $media->providerFileId,
                        $exception->getMessage()
                    ),
                    0,
                    $exception,
                );
            }

            $blob = $mediaFile->blob;

            $stored[] = [
                'media_file_id'    => $mediaFile->id,
                'name'             => $mediaFile->name,
                'kind'             => $mediaFile->kind->value,
                'mime_type'        => $blob->mime_type ?? null,
                'size'             => $blob->size ?? null,
                'provider_file_id' => $media->providerFileId,
            ];
        }

        // Array variable → append each ingested descriptor as its own element
        // (a 5-photo message yields 5 appended items). Otherwise the whole list
        // is stored as the variable value (legacy single-write behavior).
        if (null !== $variable && VariableType::Array === $variable->type) {
            $stateChanges = $this->persistElements($variable, $stored, $state, $context);
        } else {
            $stateChanges = $this->persistValue($variable, $stored, $state, $context);
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: $stateChanges,
            metadata: [self::RECEIVED_META => array_column($stored, 'media_file_id')],
        );
    }

    /**
     * @param  array<int, IncomingMedia>  $media
     *
     * @return array<int, IncomingMedia>
     */
    private function filterAcceptedMedia(array $media, InputExpectedType $expectedType): array
    {
        if (InputExpectedType::File === $expectedType) {
            return $media;
        }

        $allowedKind = match ($expectedType) {
            InputExpectedType::Image                           => 'image',
            InputExpectedType::Document                        => 'document',
            InputExpectedType::Video                           => 'video',
            InputExpectedType::Voice, InputExpectedType::Audio => 'audio',
            default                                            => null,
        };

        if (null === $allowedKind) {
            return $media;
        }

        return array_values(
            array_filter(
                $media,
                static fn (IncomingMedia $entry): bool => $entry->kind->value === $allowedKind,
            )
        );
    }

    /**
     * Load the assistant's served countries (ISO codes) for the running session
     * — the candidate regions for phone validation. Empty when none configured.
     *
     * @return list<string>
     */
    private function resolveAssistantCountries(NodeExecutionContext $context): array
    {
        $session = FlowSession::query()
            ->select(['id', 'assistant_id'])
            ->find($context->sessionId);

        if (null === $session) {
            return [];
        }

        $assistant = Assistant::query()
            ->select(['id', 'available_countries'])
            ->find($session->assistant_id);

        $countries = $assistant?->available_countries;

        return is_array($countries) ? array_values(array_filter($countries, 'is_string')) : [];
    }

    /**
     * Resolve the channel that delivered the inbound message via the contact's most
     * recent active channel association — same lookup pattern used by FlowMessageSender.
     */
    private function resolveChannel(NodeExecutionContext $context): Channel
    {
        $session = FlowSession::query()
            ->select(['id', 'assistant_id'])
            ->find($context->sessionId);

        if (null === $session) {
            throw new RuntimeException("Flow session '{$context->sessionId}' was not found.");
        }

        $channelContact = ChannelContact::query()
            ->select('channel_contacts.*')
            ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
            ->where('channel_contacts.contact_id', $context->contactId)
            ->where('channels.assistant_id', $session->assistant_id)
            ->where('channels.is_active', true)
            ->with('channel')
            ->orderByDesc('channel_contacts.last_interaction_at')
            ->first();

        if (! $channelContact instanceof ChannelContact || ! $channelContact->channel instanceof Channel) {
            throw new RuntimeException("No active channel found for contact '{$context->contactId}'.");
        }

        return $channelContact->channel;
    }

    /**
     * Resolve the configured save target. New {@code variable} shape takes
     * precedence — the legacy {@code save_to} string is parsed through
     * {@see VariableResolverInterface::fromLegacyPath()} for backward
     * compatibility with snapshots authored before the contract migration.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveVariable(array $config): ?Variable
    {
        if (is_array($config['variable'] ?? null)) {
            return Variable::tryFromArray($config['variable']);
        }

        $saveTo = $config['save_to'] ?? null;

        if (! is_string($saveTo) || '' === $saveTo) {
            return null;
        }

        return $this->variableResolver->fromLegacyPath($saveTo);
    }

    /**
     * Apply the resolved {@see Variable} to the appropriate write surface:
     * contact-scoped variables are written immediately through
     * {@see ContactWriterInterface}; session-scoped variables flow through
     * the engine's {@code stateChanges} batch.
     *
     * Array-typed variables use append semantics: contact arrays append +
     * circular-buffer inside {@see ContactWriterInterface}; session arrays append
     * into the in-state collection here (the session has no immediate writer).
     *
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function persistValue(?Variable $variable, mixed $value, array $state, NodeExecutionContext $context): array
    {
        if (null === $variable) {
            return [];
        }

        $path = $this->variableResolver->resolveTargetPath($variable);

        if (VariableStorage::Contact === $variable->storage) {
            $writer = $context->contactWriter;

            if ($writer instanceof ContactWriterInterface) {
                $writer->write($path, $value);
            }

            return [];
        }

        // Session storage: array variables append into the existing collection.
        if (VariableType::Array === $variable->type) {
            $current = data_get($state, $path);
            $list    = is_array($current) ? array_values($current) : [];
            $list[]  = $value;

            return [$path => $list];
        }

        return [$path => $value];
    }

    /**
     * Append several elements one-by-one into an array variable. Contact arrays
     * append through {@see ContactWriterInterface} per element (circular buffer
     * applied each time); session arrays accumulate into a single batched list.
     *
     * @param  list<mixed>           $elements
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function persistElements(Variable $variable, array $elements, array $state, NodeExecutionContext $context): array
    {
        $path = $this->variableResolver->resolveTargetPath($variable);

        if (VariableStorage::Contact === $variable->storage) {
            $writer = $context->contactWriter;

            if ($writer instanceof ContactWriterInterface) {
                foreach ($elements as $element) {
                    $writer->write($path, $element);
                }
            }

            return [];
        }

        $current = data_get($state, $path);
        $list    = is_array($current) ? array_values($current) : [];

        foreach ($elements as $element) {
            $list[] = $element;
        }

        return [$path => $list];
    }
}
