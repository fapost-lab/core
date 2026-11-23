<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContactFlowStartDispatcherInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\DTOs\ContactFlowStart;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Services\ContactChannelProbe;
use App\Domains\Flow\Services\ContactFlowStartCollision;
use App\Domains\Flow\Services\EventChainContext;
use App\Domains\Flow\Subflow\SubflowInputEvaluator;
use App\Domains\Flow\Subflow\SubflowMapping;
use App\Domains\Flow\Subflow\SubflowMappingValidator;
use App\Domains\Flow\Support\AddresseeResolver;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\DeclaresContextRequirementInterface;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\NodeContextRequirement;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\FlowPickerField;
use Fapost\Support\Builder\Schema\Fields\KeyValueField;
use Fapost\Support\Builder\Schema\Fields\NumberField;
use Fapost\Support\Builder\Schema\Fields\ObjectField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts another flow as a conversation of a contact the node names by an expression, and goes on at once — it does
 * not wait for that conversation (a `subflow` does).
 *
 * The conversation is the contact's with the bot of the target flow (its draft's assistant), so the node works in a
 * run without a contact or an assistant (a schedule, a webhook, an API call, a Core event) as in a conversation. The
 * node never falls back to the run's contact. It leaves by:
 *  - `started` — the start is queued ({@see \App\Jobs\Flow\StartFlowForContactJob}), once per step: the start key is
 *    the engine's execution key and the node id, unique on the started session;
 *  - `busy` — the contact's thread is with an operator, a live conversation with that bot moved within
 *    `busy_window_minutes` or sleeps in a `delay`, or the live conversation is the one asking
 *    ({@see ContactFlowStartCollision});
 *  - `no_channel` — the contact has no channel on an active channel of that bot;
 *  - `error` — `addressee_unresolved`, `contact_not_found`, `target_inactive` or `chain_depth_exceeded`.
 *
 * The started conversation is one event-chain level deeper than the run, so flows that start each other stop after
 * {@see FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH} links. The job checks everything again under the
 * contact's lock; what it decides holds, and the run does not learn of it.
 */
final class StartFlowNodeHandler extends AbstractVersionedHandler implements DeclaresContextRequirementInterface, NodeConfigValidatorInterface
{
    final public const string TYPE = 'start_flow';

    public const string HANDLE_STARTED = 'started';

    public const string HANDLE_BUSY = 'busy';

    public const string HANDLE_NO_CHANNEL = 'no_channel';

    public const string HANDLE_ERROR = 'error';

    public const string BUSY_WINDOW_FIELD = 'busy_window_minutes';

    public const int DEFAULT_BUSY_WINDOW_MINUTES = ContactFlowStartCollision::DEFAULT_BUSY_WINDOW_MINUTES;

    public const int MAX_BUSY_WINDOW_MINUTES = 1440;

    public function __construct(
        private readonly AddresseeResolver $addressees,
        private readonly ContactServiceInterface $contacts,
        private readonly FlowDefinitionRepositoryInterface $definitions,
        private readonly FlowDraftRepositoryInterface $drafts,
        private readonly FlowSessionRepositoryInterface $sessions,
        private readonly ContactChannelProbe $channels,
        private readonly ContactFlowStartCollision $collision,
        private readonly EventChainContext $eventChain,
        private readonly SubflowInputEvaluator $inputs,
        private readonly SubflowMappingValidator $mappingValidator,
        private readonly ContactFlowStartDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The key of this step's start: the engine's execution key — new with each persisted step, the same on a repeat
     * of the step — and the node id. Hashed when it would not fit the column.
     */
    public static function startKey(NodeExecutionContext $context): string
    {
        $key = "{$context->idempotencyKey}:{$context->nodeId}";

        return mb_strlen($key) <= FlowRunOrigin::MAX_START_KEY_LENGTH ? $key : 'sha256:' . hash('sha256', $key);
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
        return (string) __('builder.nodes.start_flow.label');
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
        return Schema::make()
            ->required(['flow_id'])
            ->section(
                Section::make('target', (string) __('builder.nodes.start_flow.section_target'))
                    ->icon('arrow-right-circle')
                    ->fields([
                        FlowPickerField::make('flow_id')
                            ->label((string) __('builder.nodes.start_flow.flow'))
                            ->required()
                            ->help((string) __('builder.nodes.start_flow.flow_help')),
                    ]),
            )
            ->section(
                Section::make('addressee', (string) __('builder.nodes.start_flow.section_addressee'))
                    ->icon('user')
                    ->fields([
                        ObjectField::make('addressee')
                            ->label((string) __('builder.nodes.start_flow.addressee'))
                            ->help((string) __('builder.nodes.start_flow.addressee_help'))
                            ->fields([
                                SelectField::make('kind')
                                    ->label((string) __('builder.nodes.start_flow.addressee_kind'))
                                    ->options([AddresseeResolver::KIND_CONTACT])
                                    ->default(AddresseeResolver::KIND_CONTACT),
                                TextField::make('contact')
                                    ->label((string) __('builder.nodes.start_flow.addressee_contact'))
                                    ->placeholder('{{flow.contact_id}}'),
                            ]),
                    ]),
            )
            ->section(
                Section::make('mapping', (string) __('builder.nodes.start_flow.section_mapping'))
                    ->icon('adjustments-horizontal')
                    ->collapsed()
                    ->fields([
                        KeyValueField::make(SubflowMapping::INPUT)
                            ->label((string) __('builder.nodes.start_flow.input_mapping'))
                            ->keyLabel((string) __('builder.nodes.start_flow.input_key'))
                            ->valueLabel((string) __('builder.nodes.start_flow.input_value'))
                            ->placeholder(['order_id' => '{{flow.order_id}}'])
                            ->help((string) __('builder.nodes.start_flow.input_mapping_help')),
                    ]),
            )
            ->section(
                Section::make('busy', (string) __('builder.nodes.start_flow.section_busy'))
                    ->icon('clock')
                    ->collapsed()
                    ->fields([
                        NumberField::make(self::BUSY_WINDOW_FIELD)
                            ->label((string) __('builder.nodes.start_flow.busy_window'))
                            ->min(0)
                            ->max(self::MAX_BUSY_WINDOW_MINUTES)
                            ->default(self::DEFAULT_BUSY_WINDOW_MINUTES)
                            ->help((string) __('builder.nodes.start_flow.busy_window_help')),
                    ]),
            )
            ->toArray();
    }

    /**
     * The addressee is required and of a known kind, the busy window a whole number of minutes in range, and the
     * input mapping well formed. The target flow is checked against the flow's assistant by the publish checks.
     *
     * @param  array<string, mixed>  $node
     *
     * @return list<NodeConfigIssue>
     */
    public function validate(array $node): array
    {
        $config    = is_array($node['config'] ?? null) ? $node['config'] : [];
        $addressee = AddresseeResolver::addresseeOf($config);
        $issues    = [];

        if (null === $addressee['kind']) {
            $issues[] = NodeConfigIssue::error(
                'addressee_required',
                'Name the contact to start the flow for: the node never starts it for the contact of the run.',
                'addressee.contact',
            );
        } elseif (AddresseeResolver::KIND_CONTACT !== $addressee['kind']) {
            $issues[] = NodeConfigIssue::error(
                'addressee_kind_unknown',
                "The addressee kind '{$addressee['kind']}' is not supported; use 'contact'.",
                'addressee.kind',
                ['kind' => (string) $addressee['kind']],
            );
        }

        if (null === self::busyWindowOf($config)) {
            $issues[] = NodeConfigIssue::error(
                'start_flow_invalid_busy_window',
                sprintf('busy_window_minutes must be a whole number from 0 to %d.', self::MAX_BUSY_WINDOW_MINUTES),
                self::BUSY_WINDOW_FIELD,
                ['max' => self::MAX_BUSY_WINDOW_MINUTES],
            );
        }

        $mapping = [SubflowMapping::INPUT => $config[SubflowMapping::INPUT] ?? null];

        return [...$issues, ...$this->mappingValidator->validate($mapping)];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $flowId = is_string($config['flow_id'] ?? null) ? mb_trim($config['flow_id']) : '';
        $window = self::busyWindowOf($config);

        if ('' === $flowId) {
            throw new InvalidNodeConfigException('start_flow: missing flow_id');
        }

        if (null === $window) {
            throw new InvalidNodeConfigException('start_flow: invalid busy_window_minutes');
        }

        $contactId = $this->addressees->resolve(AddresseeResolver::addresseeOf($config), $context, $state);

        if (null === $contactId) {
            return $this->error('addressee_unresolved', 'The addressee gave no contact id.', ['flow_id' => $flowId]);
        }

        $outcome = ['flow_id' => $flowId, 'contact_id' => $contactId];

        $contact = $this->findContact($contactId, $context->tenantId);

        if (null === $contact) {
            return $this->error('contact_not_found', 'No contact of this tenant has the addressee id.', $outcome);
        }

        $startKey = self::startKey($context);
        $outcome  = [...$outcome, 'start_key' => $startKey];

        // A repeated step whose start already ran is `started`, not `busy` with its own conversation.
        $started = $this->sessions->findByStartKey($startKey);

        if (null !== $started) {
            return $this->leave(self::HANDLE_STARTED, [...$outcome, 'session_id' => (string) $started->getKey()]);
        }

        $assistantId = $this->targetAssistant($flowId);

        if (null === $assistantId) {
            return $this->error('target_inactive', "The flow '{$flowId}' has no active version to start.", $outcome);
        }

        $thread = $this->channels->deliveryThread($context->tenantId, $contactId, $assistantId);

        if (null === $thread) {
            return $this->leave(self::HANDLE_NO_CHANNEL, $outcome);
        }

        $depth = $this->eventChain->current() + 1;

        if ($depth > FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH) {
            $this->logger->warning('flow.event.chain_depth_exceeded', [
                'tenant_id'  => $context->tenantId,
                'flow_id'    => $flowId,
                'depth'      => $depth,
                'max_depth'  => FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH,
                'session_id' => $context->sessionId,
                'node_id'    => $context->nodeId,
            ]);

            return $this->error('chain_depth_exceeded', 'The chain of flows starting each other is too deep.', $outcome);
        }

        $verdict = $this->collision->assess($thread, $window, $context->sessionId);

        if ($verdict->isBusy()) {
            return $this->leave(self::HANDLE_BUSY, [...$outcome, 'reason' => $verdict->busyReason]);
        }

        $this->dispatcher->dispatch(new ContactFlowStart(
            tenantId: $context->tenantId,
            flowId: $flowId,
            contactId: $contactId,
            input: $this->inputs->evaluate($config, $state, $context, self::TYPE),
            startKey: $startKey,
            chainDepth: $depth,
            busyWindowMinutes: $window,
            askingSessionId: $context->sessionId,
        ));

        return $this->leave(self::HANDLE_STARTED, $outcome);
    }

    /**
     * The busy window in minutes: the default when absent, null when it is not a whole number in range.
     *
     * @param  array<array-key, mixed>  $config
     */
    private static function busyWindowOf(array $config): ?int
    {
        $raw = $config[self::BUSY_WINDOW_FIELD] ?? null;

        if (null === $raw || '' === $raw) {
            return self::DEFAULT_BUSY_WINDOW_MINUTES;
        }

        if (is_string($raw) && 1 === preg_match('/^\d+$/', $raw)) {
            $raw = (int) $raw;
        }

        if (is_float($raw) && floor($raw) === $raw) {
            $raw = (int) $raw;
        }

        if (! is_int($raw) || $raw < 0 || $raw > self::MAX_BUSY_WINDOW_MINUTES) {
            return null;
        }

        return $raw;
    }

    private function findContact(string $contactId, string $tenantId): ?Contact
    {
        try {
            $contact = $this->contacts->findById($contactId);
        } catch (Throwable) {
            return null;
        }

        return (string) $contact->tenant_id === $tenantId ? $contact : null;
    }

    /**
     * The assistant of the target flow, while the flow has an active runnable definition; null otherwise.
     */
    private function targetAssistant(string $flowId): ?string
    {
        if (null === $this->definitions->findLatestActiveByFlowId($flowId)) {
            return null;
        }

        try {
            $assistantId = (string) $this->drafts->findByFlowId($flowId)->assistant_id;
        } catch (Throwable) {
            return null;
        }

        return '' !== $assistantId ? $assistantId : null;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function leave(string $handle, array $outcome): NodeExecutionResult
    {
        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $handle,
            metadata: $outcome,
        );
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function error(string $type, string $message, array $outcome): NodeExecutionResult
    {
        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: self::HANDLE_ERROR,
            metadata: [...$outcome, 'error' => $message, 'error_type' => $type],
            errorMessage: "{$type}: {$message}",
        );
    }
}
