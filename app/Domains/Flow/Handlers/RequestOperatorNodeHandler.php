<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Conversation\DTO\OperatorRequest;
use App\Domains\Conversation\DTO\OperatorRequestDraft;
use App\Domains\Conversation\Enums\OperatorRequestPlacementOutcome;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\OperatorRequestTimeoutSchedulerInterface;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\Support\OperatorRequestCancelButton;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\StaffNotifyTarget;
use Carbon\CarbonImmutable;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Enums\KeyboardMode;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Foundation\Messaging\ChannelCapability;
use Fapost\Support\Builder\Schema\Fields\ArrayField;
use Fapost\Support\Builder\Schema\Fields\NumberField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Fields\ToggleField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `request_operator` node — asks for a human operator and waits.
 *
 * The thread goes from the bot to `queued` (the bot stays silent meanwhile), the operators the node names are notified
 * in their bell (and by email), and the contact gets the node's "request sent" text, with a "Cancel request" button
 * where the channel has callback buttons. The session then waits in the node and leaves it by:
 *
 * - `returned` — an operator accepted the thread and has finished it ("operator left" is sent first);
 * - `cancelled` — the contact pressed the cancel button;
 * - `timeout` — nobody accepted it within the node's wait (0 waits without a limit), or the request could not be
 *   placed at all: the tenant keeps no conversation log (an operator would see an empty thread) or the contact has no
 *   active channel.
 *
 * The request is recorded on the thread, not in the session (Conversation's {@see OperatorRequestsInterface}), so it
 * outlives the session; the node keeps only its id in `system.request_operator.{nodeId}`. It is woken by a job that
 * follows the request's state change, and reads the outcome from the request itself, so the handle is always chosen
 * here. A thread an operator already waits for or handles is not requested again: the session waits for that state.
 */
final class RequestOperatorNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'request_operator';

    final public const string RETURNED = 'returned';

    final public const string CANCELLED = 'cancelled';

    final public const string TIMEOUT = 'timeout';

    /** How long the node waits for an operator when the author did not say. */
    final public const int DEFAULT_WAIT_SECONDS = 300;

    public function __construct(
        private readonly OperatorRequestsInterface $requests,
        private readonly MessageSenderInterface $sender,
        private readonly InlineKeyboardEditorInterface $keyboardEditor,
        private readonly OperatorRequestTimeoutSchedulerInterface $timeouts,
        private readonly ChannelRegistryInterface $channels,
        private readonly ContentTranslatorInterface $translator,
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
        return 'Conversation';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        $byRole  = ['target' => StaffNotifyTarget::Role->value];
        $byUsers = ['target' => StaffNotifyTarget::Users->value];

        return Schema::make()
            ->defaultConfig([
                'target'            => StaffNotifyTarget::Assistant->value,
                'notify_by_email'   => false,
                'requested_text'    => (string) __('builder.nodes.request_operator.defaults.requested_text'),
                'cancel_button'     => true,
                'cancel_label'      => (string) __('builder.nodes.request_operator.defaults.cancel_label'),
                'connected_text'    => '',
                'disconnected_text' => (string) __('builder.nodes.request_operator.defaults.disconnected_text'),
                'wait_seconds'      => self::DEFAULT_WAIT_SECONDS,
            ])
            ->section(
                Section::make('operators', (string) __('builder.nodes.request_operator.section_operators'))
                    ->icon('users')
                    ->fields([
                        SelectField::make('target')
                            ->label((string) __('builder.nodes.request_operator.target'))
                            ->default(StaffNotifyTarget::Assistant->value)
                            ->options(StaffNotifyTarget::options())
                            ->help((string) __('builder.nodes.request_operator.target_help')),
                        SelectField::make('role')
                            ->label((string) __('builder.nodes.request_operator.role'))
                            ->options($this->roleOptions())
                            ->visibleWhen($byRole),
                        ArrayField::make('user_ids')
                            ->label((string) __('builder.nodes.request_operator.user_ids'))
                            ->visibleWhen($byUsers),
                        ToggleField::make('notify_by_email')
                            ->label((string) __('builder.nodes.request_operator.notify_by_email'))
                            ->default(false),
                        TextareaField::make('operator_text')
                            ->label((string) __('builder.nodes.request_operator.operator_text'))
                            ->help((string) __('builder.nodes.request_operator.operator_text_help')),
                        NumberField::make('wait_seconds')
                            ->label((string) __('builder.nodes.request_operator.wait_seconds'))
                            ->default(self::DEFAULT_WAIT_SECONDS)
                            ->min(0)
                            ->help((string) __('builder.nodes.request_operator.wait_seconds_help')),
                    ]),
            )
            ->section(
                Section::make('contact', (string) __('builder.nodes.request_operator.section_contact'))
                    ->icon('chat-bubble-left-right')
                    ->fields([
                        TextareaField::make('requested_text')
                            ->label((string) __('builder.nodes.request_operator.requested_text')),
                        ToggleField::make('cancel_button')
                            ->label((string) __('builder.nodes.request_operator.cancel_button'))
                            ->default(true)
                            ->help((string) __('builder.nodes.request_operator.cancel_button_help')),
                        TextField::make('cancel_label')
                            ->label((string) __('builder.nodes.request_operator.cancel_label'))
                            ->visibleWhen(['cancel_button' => true]),
                        TextareaField::make('connected_text')
                            ->label((string) __('builder.nodes.request_operator.connected_text'))
                            ->help((string) __('builder.nodes.request_operator.connected_text_help')),
                        TextareaField::make('disconnected_text')
                            ->label((string) __('builder.nodes.request_operator.disconnected_text')),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config    = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $markerKey = SystemStateKeys::REQUEST_OPERATOR_PREFIX . ".{$context->nodeId}";
        $requestId = data_get($state, $markerKey);

        if (is_string($requestId) && '' !== $requestId) {
            $request = $this->requests->find($requestId);

            // Woken: by the job that follows the request, or by a message the contact sent once the thread was back
            // with the bot. Either way the request says where to go.
            if (null !== $context->incoming) {
                return $this->leave($request, $requestId, $context);
            }

            // Run again while its request still waits: nothing to do twice.
            if (null !== $request && $request->state->isWaiting() && $request->sessionId === $context->sessionId && $request->nodeId === $context->nodeId) {
                return NodeExecutionResult::waiting();
            }

            // The request this node placed before has ended: a new pass (a loop) asks again.
        }

        return $this->place($config, $state, $context, $markerKey);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function place(array $config, array $state, NodeExecutionContext $context, string $markerKey): NodeExecutionResult
    {
        $assistantId = FlowSession::query()->whereKey($context->sessionId)->value('assistant_id');

        $placement = $this->requests->place(new OperatorRequestDraft(
            tenantId: $context->tenantId,
            assistantId: is_string($assistantId) ? $assistantId : '',
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            nodeId: $context->nodeId,
            recipients: $this->recipients($config),
            byEmail: true === ($config['notify_by_email'] ?? false),
            operatorNote: $this->operatorNote($config, $state, $context),
            connectedText: $this->contactText($config['connected_text'] ?? null, $state, $context),
            disconnectedText: $this->contactText($config['disconnected_text'] ?? null, $state, $context),
        ));

        $request = $placement->request;

        if (null === $request || OperatorRequestPlacementOutcome::LoggingDisabled === $placement->outcome || OperatorRequestPlacementOutcome::NoChannel === $placement->outcome) {
            $this->logger->warning('flow.request_operator.not_placed', [
                'tenant_id'  => $context->tenantId,
                'session_id' => $context->sessionId,
                'node_id'    => $context->nodeId,
                'reason'     => $placement->outcome->value,
            ]);

            return NodeExecutionResult::executed(
                sourceHandle: self::TIMEOUT,
                metadata: ['placed' => false, 'reason' => $placement->outcome->value],
            );
        }

        if (OperatorRequestPlacementOutcome::Placed === $placement->outcome) {
            $this->tellContact($config, $state, $context, $request, (string) $placement->channelType);

            $waitSeconds = $this->waitSeconds($config);

            if ($waitSeconds > 0) {
                $this->timeouts->schedule($context->tenantId, $request->id, CarbonImmutable::now()->addSeconds($waitSeconds));
            }
        }

        return NodeExecutionResult::waiting(
            stateChanges: [$markerKey => $request->id],
            metadata: ['request_id' => $request->id, 'placement' => $placement->outcome->value],
        );
    }

    /**
     * The outcome of the request, as this node's handle; still waiting while it is pending or accepted.
     */
    private function leave(?OperatorRequest $request, string $requestId, NodeExecutionContext $context): NodeExecutionResult
    {
        if (null === $request) {
            // No thread holds the request any more: nothing to wait for. Not expected, but a session must not hang.
            $this->logger->warning('flow.request_operator.request_missing', [
                'session_id' => $context->sessionId,
                'node_id'    => $context->nodeId,
                'request_id' => $requestId,
            ]);

            return NodeExecutionResult::executed(sourceHandle: self::CANCELLED, metadata: ['request_id' => $requestId]);
        }

        $handle = match ($request->state) {
            OperatorRequestState::Returned  => self::RETURNED,
            OperatorRequestState::Cancelled => self::CANCELLED,
            OperatorRequestState::Timeout   => self::TIMEOUT,
            default                         => null,
        };

        return null === $handle
            ? NodeExecutionResult::waiting()
            : NodeExecutionResult::executed(sourceHandle: $handle, metadata: ['request_id' => $request->id]);
    }

    /**
     * "Request sent", with the cancel button where the channel shows callback buttons. Keyed by the request, so the
     * same request is never announced twice; a failure to send leaves the request waiting.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function tellContact(array $config, array $state, NodeExecutionContext $context, OperatorRequest $request, string $channelType): void
    {
        $text = $this->contactText($config['requested_text'] ?? null, $state, $context);

        if (null === $text) {
            return;
        }

        $label      = $this->contactText($config['cancel_label'] ?? null, $state, $context);
        $withButton = false !== ($config['cancel_button'] ?? true)
            && null !== $label
            && $this->channels->capabilities($channelType)->supports(ChannelCapability::CallbackButtons);

        try {
            $messageId = $this->sender->send(
                tenantId: $context->tenantId,
                contactId: $context->contactId,
                sessionId: $context->sessionId,
                payload: [
                    'content_type'    => $withButton ? SendMessageContentType::TextWithKeyboard->value : SendMessageContentType::Text->value,
                    'text'            => $text,
                    'keyboard_mode'   => $withButton ? KeyboardMode::Inline->value : null,
                    'buttons'         => $withButton ? [['id' => OperatorRequestCancelButton::ID, 'label' => $label, 'row' => 0, 'order' => 0]] : [],
                    'session_id'      => $context->sessionId,
                    'node_id'         => $context->nodeId,
                    'idempotency_key' => "{$request->id}:requested",
                ],
            );
        } catch (Throwable $exception) {
            $this->logger->warning('flow.request_operator.contact_text_failed', [
                'tenant_id'  => $context->tenantId,
                'session_id' => $context->sessionId,
                'node_id'    => $context->nodeId,
                'request_id' => $request->id,
                'exception'  => $exception->getMessage(),
            ]);

            return;
        }

        // The request may have been accepted, cancelled or timed out while the message was on its way: then nobody
        // else knows of this button, so it comes off here.
        if ($withButton && ! $this->requests->attachMessage($request->id, $messageId, $context->sessionId)) {
            $this->keyboardEditor->removeKeyboard($context->tenantId, $context->contactId, $context->sessionId, $messageId);
        }
    }

    /**
     * A text for the contact, in their language, with the flow's variables filled in; null when the author left it
     * empty.
     *
     * @param  array<string, mixed>  $state
     */
    private function contactText(mixed $field, array $state, NodeExecutionContext $context): ?string
    {
        if (! is_string($field) && ! is_array($field)) {
            return null;
        }

        $resolved = $this->translator->resolveField($field, $context->resolvedLanguage);
        $rendered = $this->templates->render($resolved, $context, $state);

        return is_string($rendered) && '' !== mb_trim($rendered) ? $rendered : null;
    }

    /**
     * The node's text for the operators (the admin language, as `notify` has it); null gives the standard one.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function operatorNote(array $config, array $state, NodeExecutionContext $context): ?string
    {
        $rendered = $this->templates->render($config['operator_text'] ?? null, $context, $state);

        return is_string($rendered) && '' !== mb_trim($rendered) ? mb_trim($rendered) : null;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array{target: string, role: string|null, user_ids: list<string>}
     */
    private function recipients(array $config): array
    {
        $target = StaffNotifyTarget::tryFrom(is_string($config['target'] ?? null) ? $config['target'] : '') ?? StaffNotifyTarget::Assistant;
        $role   = is_string($config['role'] ?? null) && '' !== mb_trim($config['role']) ? mb_trim($config['role']) : null;
        $users  = is_array($config['user_ids'] ?? null)
            ? array_values(array_filter($config['user_ids'], static fn (mixed $id): bool => is_string($id) && '' !== mb_trim($id)))
            : [];

        return ['target' => $target->value, 'role' => $role, 'user_ids' => $users];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function waitSeconds(array $config): int
    {
        $seconds = $config['wait_seconds'] ?? self::DEFAULT_WAIT_SECONDS;

        return is_numeric($seconds) ? max(0, (int) $seconds) : self::DEFAULT_WAIT_SECONDS;
    }

    /**
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
