<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Services\SegmentHandOff;
use App\Domains\Flow\Webhook\InboundFireKey;
use App\Domains\Flow\Webhook\WebhookReply;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Domains\Flow\Webhook\WebhookReplyType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\DeclaresContextRequirementInterface;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\NodeContextRequirement;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\JsonField;
use Fapost\Support\Builder\Schema\Fields\NumberField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * "Respond to webhook": hands the caller that started this run — a webhook's sender or an API trigger's caller — the
 * flow's answer, a status of 200–499 and a JSON or text body rendered from the run's state, while that caller waits (a
 * webhook or API trigger in `response_mode: sync`).
 *
 * The answer is keyed by the `(trigger_id, fire_key)` of the run's root, so a node inside a subflow answers too. The
 * first answer of a run wins: a repeat of this step, a second such node, and the end of the run change nothing
 * ({@see WebhookReplyStoreInterface}). Outside a wait — an async trigger, a wait that gave up, a run no webhook
 * or API call started — the node does nothing and goes on. Either way it takes its one exit, `default`: a webhook answer is never a
 * reason to fail the run. A rendered body over {@see WebhookReply::MAX_BODY_BYTES} is not sent (logged); the end of the
 * run answers instead.
 *
 * Needs no contact: it exists for contactless runs.
 */
final class WebhookResponseNodeHandler extends AbstractVersionedHandler implements DeclaresContextRequirementInterface, NodeConfigValidatorInterface
{
    final public const string TYPE = 'webhook_response';

    public const int DEFAULT_STATUS = 200;

    public function __construct(
        private readonly FlowSessionRepositoryInterface $sessions,
        private readonly SegmentHandOff $handOff,
        private readonly WebhookReplyStoreInterface $replies,
        private readonly TemplateRenderer $templates,
        private readonly LoggerInterface $logger,
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

    public function label(): string
    {
        return (string) __('builder.nodes.webhook_response.label');
    }

    public function contextRequirement(): NodeContextRequirement
    {
        return NodeContextRequirement::Any;
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        $json = ['body_type' => WebhookReplyType::Json->value];
        $text = ['body_type' => WebhookReplyType::Text->value];

        return Schema::make()
            ->section(
                Section::make('response', (string) __('builder.nodes.webhook_response.section_response'))
                    ->icon('bolt')
                    ->fields([
                        NumberField::make('status')
                            ->label((string) __('builder.nodes.webhook_response.status'))
                            ->help((string) __('builder.nodes.webhook_response.status_help'))
                            ->required()
                            ->default(self::DEFAULT_STATUS)
                            ->min(WebhookReply::MIN_STATUS)
                            ->max(WebhookReply::MAX_NODE_STATUS),
                        SelectField::make('body_type')
                            ->label((string) __('builder.nodes.webhook_response.body_type'))
                            ->required()
                            ->default(WebhookReplyType::Json->value)
                            ->options(WebhookReplyType::options()),
                        JsonField::make('body')
                            ->label((string) __('builder.nodes.webhook_response.body'))
                            ->default([])
                            ->visibleWhen($json),
                        TextareaField::make('text')
                            ->label((string) __('builder.nodes.webhook_response.text'))
                            ->visibleWhen($text),
                    ]),
            )
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @return list<NodeConfigIssue>
     */
    public function validate(array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $issues = [];

        if (null === self::status($config['status'] ?? self::DEFAULT_STATUS)) {
            $issues[] = NodeConfigIssue::error(
                'webhook_response_invalid_status',
                'The response status must be a whole number from ' . WebhookReply::MIN_STATUS . ' to ' . WebhookReply::MAX_NODE_STATUS . '.',
                'status',
                ['min' => WebhookReply::MIN_STATUS, 'max' => WebhookReply::MAX_NODE_STATUS],
            );
        }

        $type = WebhookReplyType::fromValue($config['body_type'] ?? WebhookReplyType::Json->value);

        if (null === $type) {
            $values   = implode(' or ', array_map(static fn (WebhookReplyType $case): string => $case->value, WebhookReplyType::cases()));
            $issues[] = NodeConfigIssue::error('webhook_response_invalid_body_type', "The body type must be {$values}.", 'body_type');
        }

        if (WebhookReplyType::Json === $type && array_key_exists('body', $config) && ! is_array($config['body'])) {
            $issues[] = NodeConfigIssue::error('webhook_response_invalid_body', 'A JSON body must be an object or a list.', 'body');
        }

        if (WebhookReplyType::Text === $type && array_key_exists('text', $config) && null !== $config['text'] && ! is_string($config['text'])) {
            $issues[] = NodeConfigIssue::error('webhook_response_invalid_text', 'A text body must be text.', 'text');
        }

        return $issues;
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $done = new NodeExecutionResult(status: NodeExecutionStatus::Executed, sourceHandle: 'default');

        $session = $this->sessions->findById($context->sessionId);
        $root    = null === $session ? null : $this->sessions->findById($this->handOff->rootIdOf($session));

        if (null === $root) {
            return $done;
        }

        $triggerId = $root->trigger_id;
        $fireKey   = $root->fire_key;

        // No webhook or API call started this run, so nobody can be waiting for it.
        if (! is_string($triggerId) || ! is_string($fireKey) || ! InboundFireKey::isInbound($fireKey)) {
            return $done;
        }

        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $reply  = $this->reply($config, $context, $state);

        if (null === $reply) {
            return $done;
        }

        $this->replies->keep((string) $root->tenant_id, $triggerId, $fireKey, $reply);

        return $done;
    }

    /**
     * A status from the config: an integer, or digits, within 200–499; null for anything else.
     */
    private static function status(mixed $value): ?int
    {
        if (is_string($value) && 1 === preg_match('/^\d{3}$/D', $value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < WebhookReply::MIN_STATUS || $value > WebhookReply::MAX_NODE_STATUS) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function reply(array $config, NodeExecutionContext $context, array $state): ?WebhookReply
    {
        $status = self::status($config['status'] ?? self::DEFAULT_STATUS) ?? self::DEFAULT_STATUS;
        $type   = WebhookReplyType::fromValue($config['body_type'] ?? null) ?? WebhookReplyType::Json;

        if (WebhookReplyType::Text === $type) {
            $rendered = $this->templates->render(is_string($config['text'] ?? null) ? $config['text'] : '', $context, $state);
            $body     = is_scalar($rendered) ? (string) $rendered : '';
        } else {
            // Rendered leaf by leaf and then encoded, so a value from the state can never break or extend the JSON.
            $rendered = $this->templates->render(is_array($config['body'] ?? null) ? $config['body'] : [], $context, $state);

            try {
                $body = json_encode($rendered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                $this->skipped($context, 'unencodable_body', $exception->getMessage());

                return null;
            }
        }

        if (mb_strlen($body, '8bit') > WebhookReply::MAX_BODY_BYTES) {
            $this->skipped($context, 'body_too_large', (string) mb_strlen($body, '8bit'));

            return null;
        }

        return new WebhookReply($status, $body, $type);
    }

    private function skipped(NodeExecutionContext $context, string $reason, string $detail): void
    {
        $this->logger->warning('flow.webhook.response_skipped', [
            'tenant_id'  => $context->tenantId,
            'session_id' => $context->sessionId,
            'node_id'    => $context->nodeId,
            'reason'     => $reason,
            'detail'     => $detail,
        ]);
    }
}
