<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\AudienceBroadcasterInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\DTOs\AudienceBroadcastRequest;
use App\Domains\Flow\Enums\AudienceKind;
use App\Domains\Flow\Enums\ContactNotifyTarget;
use App\Domains\Flow\Enums\NotifyMode;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\ContactPlaceholders;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Services\EventChainContext;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\StaffNotifyChannel;
use App\Domains\Staff\Enums\StaffNotifyTarget;
use App\Domains\Staff\Jobs\SendStaffNotificationJob;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use Carbon\Carbon;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\StateNamespace;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\ArrayField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `notify` node — sends a notification from a flow, in one of two modes:
 *
 *  - **Staff** — escalates to admin-panel users (in-app / email), admin-UI
 *    language. Recipients: all assistant staff / role / explicit users.
 *  - **Contacts** — retired. A node published in this mode before still reaches
 *    contacts of its own assistant (by tag / all), but as a broadcast started
 *    through {@see AudienceBroadcasterInterface}, the same way as `send_message`
 *    to tags or a segment: a `broadcasts` row on the console, once per node
 *    execution, never while the node's previous broadcast runs. Publishing a
 *    flow that adds such a node or changes one is refused
 *    (`notify_contacts_retired`, {@see \App\Domains\Flow\Services\FlowPublishChanges}).
 *
 * Staff delivery is queued onto `messaging.system`. Both modes expose the single
 * `default` output: a contacts node that sends nothing (a refusal, a failure, or a
 * message that reads a contact) still goes on, with the reason in the trace
 * metadata (`broadcast_outcome`) and a warning in the log.
 *
 * Idempotency: each mode writes a per-node state marker, so a node already done in
 * this session does nothing again; a retried step of a contacts node gets its
 * broadcast back by the node execution's key.
 */
final class NotifyNodeHandler extends AbstractVersionedHandler implements NodeConfigValidatorInterface
{
    final public const string TYPE = 'notify';

    /** Channel pseudo-value expanding to every registered staff transport. */
    private const string CHANNEL_ALL = 'all';

    /** The trace metadata key naming what a contacts node did. */
    private const string OUTCOME_META = 'broadcast_outcome';

    /** A contacts node whose message reads a contact sends nothing. */
    private const string OUTCOME_CONTACT_EXPRESSION_REFUSED = 'contact_expression_refused';

    /** A contacts node whose broadcast could not be started for an unexpected reason. */
    private const string OUTCOME_FAILURE = 'broadcast_failure';

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly TemplateRenderer $templates,
        private readonly StaffNotifierRegistry $notifiers,
        private readonly AudienceBroadcasterInterface $broadcaster,
        private readonly FlowDefinitionRepositoryInterface $definitions,
        private readonly FlowDraftRepositoryInterface $drafts,
        private readonly EventChainContext $eventChain,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Contact';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        $staffMode    = ['mode' => NotifyMode::Staff->value];
        $contactsMode = ['mode' => NotifyMode::Contacts->value];

        return Schema::make()
            ->required(['mode'])
            ->section(
                Section::make('mode', (string) __('builder.nodes.notify.section_mode'))
                    ->icon('bell-alert')
                    ->fields([
                        SelectField::make('mode')
                            ->label((string) __('builder.nodes.notify.mode'))
                            ->required()
                            ->default(NotifyMode::Staff->value)
                            ->options(NotifyMode::options()),
                    ]),
            )
            ->section(
                Section::make('staff', (string) __('builder.nodes.notify.section_staff'))
                    ->icon('users')
                    ->fields([
                        SelectField::make('target')
                            ->label((string) __('builder.nodes.notify.target'))
                            ->default(StaffNotifyTarget::Assistant->value)
                            ->options(StaffNotifyTarget::options())
                            ->visibleWhen($staffMode),
                        SelectField::make('role')
                            ->label((string) __('builder.nodes.notify.role'))
                            ->options($this->roleOptions())
                            ->visibleWhen(['mode' => NotifyMode::Staff->value, 'target' => StaffNotifyTarget::Role->value]),
                        ArrayField::make('user_ids')
                            ->label((string) __('builder.nodes.notify.user_ids'))
                            ->visibleWhen(['mode' => NotifyMode::Staff->value, 'target' => StaffNotifyTarget::Users->value]),
                        SelectField::make('channel')
                            ->label((string) __('builder.nodes.notify.channel'))
                            ->default(StaffNotifyChannel::InApp->value)
                            ->options($this->channelOptions())
                            ->visibleWhen($staffMode),
                    ]),
            )
            ->section(
                Section::make('contacts', (string) __('builder.nodes.notify.section_contacts'))
                    ->icon('user-group')
                    ->fields([
                        SelectField::make('assistant_id')
                            ->label((string) __('builder.nodes.notify.assistant'))
                            ->visibleWhen($contactsMode),
                        SelectField::make('contact_target')
                            ->label((string) __('builder.nodes.notify.contact_target'))
                            ->default(ContactNotifyTarget::Tag->value)
                            ->options(ContactNotifyTarget::options())
                            ->visibleWhen($contactsMode),
                        ArrayField::make('tags')
                            ->label((string) __('builder.nodes.notify.tags'))
                            ->visibleWhen(['mode' => NotifyMode::Contacts->value, 'contact_target' => ContactNotifyTarget::Tag->value]),
                    ]),
            )
            ->section(
                Section::make('message', (string) __('builder.nodes.notify.section_message'))
                    ->icon('chat-bubble-left-ellipsis')
                    ->fields([
                        TextareaField::make('message')
                            ->label((string) __('builder.nodes.notify.message'))
                            ->required()
                            ->help((string) __('builder.nodes.notify.message_help')),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $mode   = NotifyMode::tryFrom(is_string($config['mode'] ?? null) ? $config['mode'] : '') ?? NotifyMode::Staff;

        return match ($mode) {
            NotifyMode::Staff    => $this->executeStaff($config, $state, $context),
            NotifyMode::Contacts => $this->executeContacts($config, $state, $context),
        };
    }

    /**
     * A contacts node is retired: it can stay in a published flow, but not be added or changed. The builder shows why,
     * without blocking; publishing such a node new or changed is refused by the publish itself, which compares the draft
     * with the published version ({@see \App\Domains\Flow\Services\FlowPublishChanges::changedContactNotifications()}).
     *
     * @param  array<string, mixed>  $node
     *
     * @return list<NodeConfigIssue>
     */
    public function validate(array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        if (NotifyMode::Contacts->value !== ($config['mode'] ?? null)) {
            return [];
        }

        $issues = [NodeConfigIssue::warning(
            'notify_contacts_deprecated',
            'Sending to contacts from Notify is retired: this step still sends as a broadcast, but it cannot be added or changed. Replace it with Send message to tags or a segment.',
            'mode',
        )];

        if (ContactPlaceholders::readsContact($config['message'] ?? null)) {
            $issues[] = NodeConfigIssue::warning(
                'notify_contacts_contact_expression',
                'This message reads a contact and goes to many people, so it is not sent: replace this step with Send message to tags or a segment, without contact placeholders.',
                'message',
            );
        }

        return $issues;
    }

    /**
     * Staff escalation: render the (admin-language) message and queue delivery.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeStaff(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        // Idempotency: skip dispatch if this node already queued a notification.
        $markerKey = SystemStateKeys::STAFF_NOTIFIED_PREFIX . ".{$context->nodeId}";

        if (true === data_get($state, $markerKey)) {
            return NodeExecutionResult::executed();
        }

        $message = $this->templates->render($config['message'] ?? null, $context, $state);
        $message = is_string($message) ? mb_trim($message) : '';

        if ('' === $message) {
            throw new InvalidNodeConfigException('notify: message is required.');
        }

        $this->dispatcher->dispatch(new SendStaffNotificationJob(
            tenantId: $context->tenantId,
            sessionId: $context->sessionId,
            nodeId: $context->nodeId,
            targetConfig: $this->staffTargetConfig($config),
            channels: $this->resolveChannels($config),
            message: $message,
        ));

        return NodeExecutionResult::executed(
            stateChanges: [$markerKey => true],
            metadata: ['mode' => NotifyMode::Staff->value, 'target' => $config['target'] ?? StaffNotifyTarget::Assistant->value],
        );
    }

    /**
     * Contacts mode (retired) — the message, every language of it rendered from the running flow, goes to the node's
     * assistant's audience (by tag / all) as a broadcast started through {@see AudienceBroadcasterInterface}.
     *
     * The node keeps its `notify@1` contract: one `default` exit whatever happens. A message that reads a contact, a
     * refusal and a failure send nothing and say why in the trace metadata and the log. The marker is written in every
     * case, so the node does its work once per session.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeContacts(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $markerKey = SystemStateKeys::CONTACTS_NOTIFIED_PREFIX . ".{$context->nodeId}";

        if (true === data_get($state, $markerKey)) {
            return NodeExecutionResult::executed();
        }

        $assistantId = is_string($config['assistant_id'] ?? null) ? mb_trim($config['assistant_id']) : '';

        if ('' === $assistantId) {
            throw new InvalidNodeConfigException('notify: an assistant is required for contacts mode.');
        }

        $target = ContactNotifyTarget::tryFrom(is_string($config['contact_target'] ?? null) ? $config['contact_target'] : '')
            ?? ContactNotifyTarget::Tag;
        $tags = is_array($config['tags'] ?? null)
            ? array_values(array_filter($config['tags'], 'is_string'))
            : [];

        $metadata = [
            'mode'           => NotifyMode::Contacts->value,
            'assistant_id'   => $assistantId,
            'contact_target' => $target->value,
            'tags'           => $tags,
        ];

        // Checked on the raw text, before rendering: the rendered text no longer shows what it read.
        if (ContactPlaceholders::readsContact($config['message'] ?? null)) {
            return $this->sentNothing(self::OUTCOME_CONTACT_EXPRESSION_REFUSED, $markerKey, $metadata, $context);
        }

        $message = $this->renderMessage($config['message'] ?? null, $state, $context);

        if (! $this->hasRenderableMessage($message)) {
            throw new InvalidNodeConfigException('notify: message is required.');
        }

        try {
            $flowDefinitionId = data_get($state, StateNamespace::System->value . '.flow_definition_id');

            if (! is_string($flowDefinitionId) || '' === $flowDefinitionId) {
                return $this->sentNothing(self::OUTCOME_FAILURE, $markerKey, $metadata, $context, 'the run names no flow definition.');
            }

            $definition = $this->definitions->findById($flowDefinitionId);
            $draft      = $this->drafts->findByFlowId((string) $definition->flow_id);

            $result = $this->broadcaster->start(new AudienceBroadcastRequest(
                tenantId: $context->tenantId,
                assistantId: $assistantId,
                name: (string) $draft->name . ' — ' . Carbon::now()->format('Y-m-d H:i'),
                audience: ContactNotifyTarget::All === $target ? AudienceKind::All : AudienceKind::Tags,
                tags: ContactNotifyTarget::All === $target ? [] : $tags,
                segmentId: null,
                message: $message,
                flowId: (string) $definition->flow_id,
                sessionId: $context->sessionId,
                nodeId: $context->nodeId,
                originKey: hash('sha256', "{$context->idempotencyKey}:{$context->nodeId}"),
                chainDepth: $this->eventChain->current() + 1,
            ));
        } catch (Throwable $exception) {
            return $this->sentNothing(self::OUTCOME_FAILURE, $markerKey, $metadata, $context, $exception->getMessage(), $exception);
        }

        if (! $result->outcome->startedBroadcast()) {
            return $this->sentNothing($result->outcome->value, $markerKey, $metadata, $context);
        }

        return NodeExecutionResult::executed(
            stateChanges: [$markerKey => true],
            metadata: [
                ...$metadata,
                'broadcast_id'     => $result->broadcastId,
                'recipient_count'  => $result->recipientCount,
                self::OUTCOME_META => $result->outcome->value,
            ],
        );
    }

    /**
     * A contacts node that sends nothing still goes on by `default` and marks itself done; the trace names the reason
     * and the log keeps a warning, since nobody waits on the node's result.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function sentNothing(
        string $outcome,
        string $markerKey,
        array $metadata,
        NodeExecutionContext $context,
        ?string $error = null,
        ?Throwable $exception = null,
    ): NodeExecutionResult {
        $this->logger->warning(
            self::OUTCOME_CONTACT_EXPRESSION_REFUSED === $outcome ? 'flow.notify_contacts_refused' : 'flow.notify_contacts_not_sent',
            array_filter([
                'tenant_id'  => $context->tenantId,
                'session_id' => $context->sessionId,
                'node_id'    => $context->nodeId,
                'outcome'    => $outcome,
                'error'      => $error,
                'exception'  => $exception,
            ], static fn (mixed $value): bool => null !== $value),
        );

        return NodeExecutionResult::executed(
            stateChanges: [$markerKey => true],
            metadata: [...$metadata, self::OUTCOME_META => $outcome],
        );
    }

    /**
     * Every language of the message, rendered now: the broadcast keeps it as it is, and Broadcasting picks each
     * recipient's language. A plain string is the base language's text.
     *
     * @param  array<string, mixed>  $state
     *
     * @return array<string, string>|string
     */
    private function renderMessage(mixed $message, array $state, NodeExecutionContext $context): array|string
    {
        $rendered = $this->templates->render($message, $context, $state);

        if (is_scalar($rendered)) {
            return (string) $rendered;
        }

        if (! is_array($rendered)) {
            return '';
        }

        $languages = [];

        foreach ($rendered as $language => $text) {
            if (is_string($language) && is_scalar($text)) {
                $languages[$language] = (string) $text;
            }
        }

        return $languages;
    }

    /**
     * Whether a rendered message carries deliverable text — a non-blank string, or a localized map with at least one
     * non-blank entry.
     *
     * @param  array<string, string>|string  $message
     */
    private function hasRenderableMessage(array|string $message): bool
    {
        foreach (is_array($message) ? $message : [$message] as $text) {
            if ('' !== mb_trim($text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract only the recipient-selection keys forwarded to the staff job.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function staffTargetConfig(array $config): array
    {
        return [
            'target'   => $config['target'] ?? StaffNotifyTarget::Assistant->value,
            'role'     => $config['role'] ?? null,
            'user_ids' => $config['user_ids'] ?? [],
        ];
    }

    /**
     * Resolve the selected staff channel into concrete transport ids. The `all`
     * pseudo-channel expands to every registered transport; an empty/unknown
     * selection falls back to in-app.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function resolveChannels(array $config): array
    {
        $channel = is_string($config['channel'] ?? null) ? $config['channel'] : StaffNotifyChannel::InApp->value;

        if (self::CHANNEL_ALL === $channel) {
            $channels = $this->notifiers->channels();

            return [] === $channels ? [StaffNotifyChannel::InApp->value] : $channels;
        }

        return [$channel];
    }

    /**
     * @return array<string, string>
     */
    private function channelOptions(): array
    {
        return StaffNotifyChannel::options()
            + [self::CHANNEL_ALL => (string) __('builder.nodes.notify.channels.all')];
    }

    /**
     * Builder dropdown for the role target. Keyed by role name so the value
     * round-trips straight into the spatie `role()` scope.
     *
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        $options = [];

        foreach (RoleEnum::cases() as $role) {
            $options[$role->value] = ucfirst(str_replace('_', ' ', $role->value));
        }

        return $options;
    }
}
