<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Contracts\MediaServiceInterface;
use FAPost\Foundation\DTO\IncomingMedia;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Contracts\ContactWriterInterface;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use RuntimeException;
use Throwable;

final class InputNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'input';

    private const string RECEIVED_META = 'received';

    private const string EXPECTED_TYPE_TEXT = 'text';

    /**
     * Expected types that route through the media ingest pipeline. The handler accepts
     * any of these values; the value also constrains what attachments are accepted on
     * an incoming message (file = anything, others narrow to a specific MediaKind).
     */
    private const array MEDIA_EXPECTED_TYPES = ['file', 'image', 'document', 'video', 'voice', 'audio'];

    public function __construct(
        private readonly MediaIngestorInterface $mediaIngestor,
        private readonly MediaServiceInterface $mediaService,
        private readonly VariableResolverInterface $variableResolver,
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
            'save_to' => [
                'type'        => 'state-picker',
                'label'       => 'Save to',
                'required'    => false,
                'placeholder' => 'flow.user_input',
            ],
            'expected_type' => [
                'type'    => 'select',
                'label'   => 'Expected input',
                'options' => array_merge([self::EXPECTED_TYPE_TEXT], self::MEDIA_EXPECTED_TYPES),
                'default' => self::EXPECTED_TYPE_TEXT,
            ],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config       = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $expectedType = is_string(
            $config['expected_type'] ?? null
        ) ? $config['expected_type'] : self::EXPECTED_TYPE_TEXT;

        $variable = $this->resolveVariable($config);

        $promptResult = $this->sendPromptIfNeeded($config, $state, $context);
        if (null !== $promptResult) {
            return $promptResult;
        }

        if (in_array($expectedType, self::MEDIA_EXPECTED_TYPES, true)) {
            return $this->handleMediaInput($expectedType, $variable, $context);
        }

        return $this->handleTextInput($variable, $context);
    }

    /**
     * Optionally send the configured prompt before parking the session
     * in `Waiting`. Idempotent through {@see SystemStateKeys::SENT_MESSAGES}:
     * once the prompt for this node id has gone out, subsequent passes
     * skip the send entirely. Returns:
     *
     *   - the result the engine should propagate (prompt was just sent
     *     → waiting), or
     *   - `null` to mean "carry on with normal input handling".
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function sendPromptIfNeeded(
        array $config,
        array $state,
        NodeExecutionContext $context
    ): ?NodeExecutionResult {
        $promptRaw = $config['prompt'] ?? null;
        if ( ! is_string($promptRaw) && ! is_array($promptRaw)) {
            return null;
        }

        $resolved = $this->translator->resolveField($promptRaw, $context->resolvedLanguage);
        if ( ! is_string($resolved) || '' === mb_trim($resolved)) {
            return null;
        }

        $sentIds = data_get($state, SystemStateKeys::SENT_MESSAGES, []);
        if (is_array($sentIds) && array_key_exists($context->nodeId, $sentIds)) {
            // Prompt already sent on a previous pass; let the input
            // handling continue (or wait, if no incoming yet).
            return null;
        }

        $externalMessageId = $this->sender->send(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            payload: [
                'content_type' => 'text',
                'text'         => $resolved,
            ],
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
     * Handle a media-typed input by ingesting every attachment on the incoming message
     * through {@see MediaIngestorInterface} and storing the resulting media descriptors
     * as a list under the configured save_to key. Multi-file messages produce multi-element
     * lists — single-file messages produce one-element lists for a uniform downstream shape.
     */
    private function handleMediaInput(
        string $expectedType,
        ?Variable $variable,
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

        $stateChanges = $this->buildStateChanges($variable, $stored, $context);

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
    private function filterAcceptedMedia(array $media, string $expectedType): array
    {
        if ('file' === $expectedType) {
            return $media;
        }

        $allowedKind = match ($expectedType) {
            'image'          => 'image',
            'document'       => 'document',
            'video'          => 'video',
            'voice', 'audio' => 'audio',
            default          => null,
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

        if ( ! $channelContact instanceof ChannelContact || ! $channelContact->channel instanceof Channel) {
            throw new RuntimeException("No active channel found for contact '{$context->contactId}'.");
        }

        return $channelContact->channel;
    }

    private function handleTextInput(?Variable $variable, NodeExecutionContext $context): NodeExecutionResult
    {
        $incomingText = $context->incoming?->text;

        if (null === $incomingText) {
            return new NodeExecutionResult(status: NodeExecutionStatus::Waiting);
        }

        $stateChanges = $this->buildStateChanges($variable, $incomingText, $context);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: $stateChanges,
            metadata: [self::RECEIVED_META => $incomingText],
        );
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

        if ( ! is_string($saveTo) || '' === $saveTo) {
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
     * @return array<string, mixed>
     */
    private function buildStateChanges(?Variable $variable, mixed $value, NodeExecutionContext $context): array
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

        return [$path => $value];
    }
}
