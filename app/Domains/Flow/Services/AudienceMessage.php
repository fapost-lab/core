<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\AudienceBroadcasterInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\DTOs\AudienceBroadcastRequest;
use App\Domains\Flow\Enums\AudienceBroadcastOutcome;
use App\Domains\Flow\Enums\AudienceKind;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Support\AddresseeResolver;
use Carbon\Carbon;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\StateNamespace;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The audience path of a `send_message` node: a message to tags or a segment, sent as a broadcast of the flow's
 * assistant through {@see AudienceBroadcasterInterface}.
 *
 * Kept out of the node handler so the handler stays the one-contact path. Not a singleton: the broadcaster it holds
 * reads the current tenant.
 */
final readonly class AudienceMessage
{
    private const string BROADCAST_ID_META    = 'broadcast_id';
    private const string RECIPIENT_COUNT_META = 'recipient_count';

    /** Where `save_broadcast_to` may write: a flow variable. */
    private const string FLOW_PATH_PATTERN = '/^flow(\.\w+)+$/';

    /** A `{{contact…}}` or `{{module…}}` placeholder of the template engine. */
    private const string CONTACT_PLACEHOLDER_PATTERN = '/\{\{\s*(contact|module)(\.\w+)*\s*\}\}/';

    public function __construct(
        private AudienceBroadcasterInterface $broadcaster,
        private EventChainContext $eventChain,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowDraftRepositoryInterface $drafts,
        private TemplateRenderer $templates,
        private LoggerInterface $logger,
    ) {
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
    public function issues(array $config, array $addressee): array
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
    public function send(array $config, array $addressee, array $state, NodeExecutionContext $context): NodeExecutionResult
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
                audience: AudienceKind::from((string) $addressee['kind']),
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
}
