<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FlowQuestionsInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\DTOs\FlowQuestionDraft;
use App\Domains\Flow\Enums\FlowQuestionStatus;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Models\FlowQuestion;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\AutomationRunDeadline;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Flow\Support\AddresseeResolver;
use App\Domains\Flow\Support\FlowQuestionButton;
use Carbon\CarbonImmutable;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\ContactWriterInterface;
use Fapost\Foundation\Flow\Contracts\DeclaresContextRequirementInterface;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\KeyboardMode;
use Fapost\Foundation\Flow\Enums\NodeContextRequirement;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Fapost\Support\Builder\Schema\Fields\NumberField;
use Fapost\Support\Builder\Schema\Fields\ObjectArrayField;
use Fapost\Support\Builder\Schema\Fields\ObjectField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * `ask_person` node — asks a contact (the addressee its config names) a question with buttons and waits for the
 * answer, or for the timeout.
 *
 * The question is recorded outside the session ({@see FlowQuestionsInterface}), so a press is recognised whatever
 * happened to the run since; the node keeps only its id in `system.ask_person.{nodeId}.question_id`. The session parks
 * `paused` until the question's deadline (`delayed(resumeAt)`): the engine schedules the wake-up, and an answer wakes it
 * earlier through `ContinueAfterFlowQuestionJob`. Woken, the node reads the outcome from the question and leaves by the
 * pressed button's handle (its id), `timeout`, or `error`.
 *
 * The addressee is never the run's contact by default, so the node works in a contactless run (`any`). In an automation
 * run the wait ends a minute before the run's deadline at the latest; with less than that left, the node times out at
 * once and asks nothing.
 */
final class AskPersonNodeHandler extends AbstractVersionedHandler implements DeclaresContextRequirementInterface, NodeConfigValidatorInterface
{
    final public const string TYPE = 'ask_person';

    final public const string TIMEOUT = 'timeout';

    final public const string ERROR = 'error';

    final public const int MIN_TIMEOUT_SECONDS = 60;

    final public const int MAX_TIMEOUT_SECONDS = 604_800;

    /** How long before the run's deadline the wait ends at the latest. */
    final public const int DEADLINE_MARGIN_SECONDS = 60;

    /** The system text a late press gets when the node sets none. */
    final public const string ALREADY_DECIDED_KEY = 'flow.ask_person.already_decided';

    public function __construct(
        private readonly FlowQuestionsInterface $questions,
        private readonly MessageSenderInterface $sender,
        private readonly InlineKeyboardEditorInterface $keyboardEditor,
        private readonly ContentTranslatorInterface $translator,
        private readonly TemplateRenderer $templates,
        private readonly AddresseeResolver $addressees,
        private readonly VariableResolverInterface $variables,
        private readonly AutomationRunDeadline $deadline,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function markerKey(string $nodeId): string
    {
        return SystemStateKeys::ASK_PERSON_PREFIX . ".{$nodeId}.question_id";
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Conversation';
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
            ->required(['addressee', 'text', 'buttons', 'timeout_seconds'])
            ->defaultConfig([
                'addressee'        => ['kind' => AddresseeResolver::KIND_CONTACT, 'contact' => ''],
                'text'             => (string) __('builder.nodes.ask_person.defaults.text'),
                'buttons'          => [],
                'timeout_seconds'  => 3600,
                'late_answer_text' => '',
            ])
            ->section(
                Section::make('addressee', (string) __('builder.nodes.ask_person.section_addressee'))
                    ->icon('user')
                    ->fields([
                        ObjectField::make('addressee')
                            ->label((string) __('builder.nodes.ask_person.addressee'))
                            ->help((string) __('builder.nodes.ask_person.addressee_help'))
                            ->fields([
                                SelectField::make('kind')
                                    ->options([AddresseeResolver::KIND_CONTACT])
                                    ->default(AddresseeResolver::KIND_CONTACT),
                                TextField::make('contact')->placeholder('{{flow.manager_id}}'),
                            ]),
                    ]),
            )
            ->section(
                Section::make('question', (string) __('builder.nodes.ask_person.section_question'))
                    ->icon('chat-bubble-left-right')
                    ->fields([
                        TextareaField::make('text')
                            ->label((string) __('builder.nodes.ask_person.text'))
                            ->help((string) __('builder.nodes.ask_person.text_help'))
                            ->required(),
                        ObjectArrayField::make('buttons')
                            ->label((string) __('builder.nodes.ask_person.buttons'))
                            ->itemLabel('{label}')
                            ->itemFields([
                                TextField::make('id')->label('ID')->required(),
                                TextField::make('label')->label((string) __('builder.nodes.ask_person.button_label'))->required(),
                                TextField::make('value')->label((string) __('builder.nodes.ask_person.button_value')),
                            ]),
                    ]),
            )
            ->section(
                Section::make('wait', (string) __('builder.nodes.ask_person.section_wait'))
                    ->icon('clock')
                    ->fields([
                        NumberField::make('timeout_seconds')
                            ->label((string) __('builder.nodes.ask_person.timeout_seconds'))
                            ->help((string) __('builder.nodes.ask_person.timeout_seconds_help'))
                            ->default(3600)
                            ->min(self::MIN_TIMEOUT_SECONDS)
                            ->max(self::MAX_TIMEOUT_SECONDS),
                    ]),
            )
            ->section(
                Section::make('answer', (string) __('builder.nodes.ask_person.section_answer'))
                    ->icon('arrow-down-tray')
                    ->collapsed()
                    ->fields([
                        TextareaField::make('late_answer_text')
                            ->label((string) __('builder.nodes.ask_person.late_answer_text'))
                            ->help((string) __('builder.nodes.ask_person.late_answer_text_help')),
                        ObjectField::make('save_to_variable')
                            ->label((string) __('builder.nodes.ask_person.save_to_variable'))
                            ->fields([
                                TextField::make('name')->label('Name')->required(),
                                SelectField::make('type')->label('Type')->options(['text', 'number', 'confirm'])->default('text'),
                                SelectField::make('storage')->label('Storage')->options(['session', 'contact'])->default('session'),
                                TextField::make('group')->label('Group'),
                            ]),
                    ]),
            )
            ->toArray();
    }

    /**
     * An addressee of kind `contact` with an expression, a question, 1…8 buttons with unique uuid ids and a label, and
     * a timeout within bounds.
     *
     * @param  array<string, mixed>  $node
     *
     * @return list<NodeConfigIssue>
     */
    public function validate(array $node): array
    {
        $config    = is_array($node['config'] ?? null) ? $node['config'] : [];
        $issues    = [];
        $addressee = AddresseeResolver::addresseeOf($config);

        if (null === $addressee['kind']) {
            $issues[] = NodeConfigIssue::error('addressee_required', 'Name the contact to ask: an expression that gives a contact id.', 'addressee.contact');
        } elseif (AddresseeResolver::KIND_CONTACT !== $addressee['kind']) {
            $issues[] = NodeConfigIssue::error('addressee_kind_unknown', "The addressee kind '{$addressee['kind']}' is not supported; use 'contact'.", 'addressee.kind', ['kind' => (string) $addressee['kind']]);
        }

        if (! $this->hasText($config['text'] ?? null)) {
            $issues[] = NodeConfigIssue::error('text_required', 'The question has no text.', 'text');
        }

        $buttons = is_array($config['buttons'] ?? null) ? array_values($config['buttons']) : [];

        if ([] === $buttons) {
            $issues[] = NodeConfigIssue::error('buttons_required', 'Add at least one answer button.', 'buttons');
        } elseif (count($buttons) > FlowQuestionButton::MAX_BUTTONS) {
            $issues[] = NodeConfigIssue::error('buttons_too_many', 'A question takes at most ' . FlowQuestionButton::MAX_BUTTONS . ' buttons.', 'buttons', ['max' => FlowQuestionButton::MAX_BUTTONS]);
        }

        $seen = [];

        foreach ($buttons as $index => $button) {
            // Two ids that differ only in case are one uuid: refused as a duplicate.
            $id = is_array($button) && is_string($button['id'] ?? null) ? mb_strtolower($button['id']) : '';

            if (! Uuid::isValid($id)) {
                $issues[] = NodeConfigIssue::error('button_id_invalid', 'A button has no stable uuid id.', "buttons.{$index}.id");
            } elseif (isset($seen[$id])) {
                $issues[] = NodeConfigIssue::error('button_id_duplicate', 'Two buttons share an id.', "buttons.{$index}.id");
            }

            $seen[$id] = true;

            if (! is_array($button) || ! $this->hasText($button['label'] ?? null)) {
                $issues[] = NodeConfigIssue::error('button_label_required', 'A button has no label.', "buttons.{$index}.label");
            }
        }

        $timeout = $config['timeout_seconds'] ?? null;

        if (! is_numeric($timeout) || (int) $timeout < self::MIN_TIMEOUT_SECONDS || (int) $timeout > self::MAX_TIMEOUT_SECONDS) {
            $issues[] = NodeConfigIssue::error(
                'timeout_out_of_range',
                'The wait must be from ' . self::MIN_TIMEOUT_SECONDS . ' seconds to 7 days.',
                'timeout_seconds',
                ['min' => self::MIN_TIMEOUT_SECONDS],
            );
        }

        return $issues;
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config     = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $markerKey  = self::markerKey($context->nodeId);
        $questionId = data_get($state, $markerKey);

        if (is_string($questionId) && '' !== $questionId) {
            return $this->wake($questionId, $config, $context);
        }

        return $this->ask($config, $state, $context);
    }

    /**
     * The first visit: record the question, send it, park until its deadline.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function ask(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $recipientId = $this->addressees->resolve(AddresseeResolver::addresseeOf($config), $context, $state);

        if (null === $recipientId) {
            return $this->error('addressee_unresolved', 'the addressee gave no contact id.');
        }

        $buttons = $this->buttons($config);

        if ([] === $buttons || count($buttons) > FlowQuestionButton::MAX_BUTTONS) {
            return $this->error('invalid_config', 'the question needs 1 to ' . FlowQuestionButton::MAX_BUTTONS . ' buttons with uuid ids.');
        }

        $session = FlowSession::query()->select(['id', 'assistant_id', 'run_kind', 'deadline_at', 'created_at'])->find($context->sessionId);

        if (! $session instanceof FlowSession || null === $session->assistant_id || '' === (string) $session->assistant_id) {
            return $this->error('send_failure', 'the run has no assistant to send the question through.');
        }

        $now       = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($this->timeoutSeconds($config));
        $clamped   = false;
        $runEnd    = $this->runDeadline($session);

        if (null !== $runEnd) {
            $latest = $runEnd->subSeconds(self::DEADLINE_MARGIN_SECONDS);

            if ($latest->lte($now)) {
                return NodeExecutionResult::executed(
                    sourceHandle: self::TIMEOUT,
                    stateChanges: [$this->markerKeyFor($context) => null],
                    metadata: ['timed_out_at' => $now->toIso8601String(), 'clamped_by_run_deadline' => true, 'asked' => false],
                );
            }

            if ($latest->lt($expiresAt)) {
                $expiresAt = $latest;
                $clamped   = true;
            }
        }

        $question = $this->questions->open(new FlowQuestionDraft(
            tenantId: $context->tenantId,
            sessionId: $context->sessionId,
            nodeId: $context->nodeId,
            executionKey: $context->idempotencyKey,
            contactId: $recipientId,
            assistantId: (string) $session->assistant_id,
            buttons: array_map(static fn (array $button): array => ['id' => $button['id'], 'value' => $button['value']], $buttons),
            expiresAt: $expiresAt,
            lateAnswerText: $this->lateAnswerText($config, $state, $context),
        ));

        // A retried step whose question already left `pending` reads its outcome instead of asking again.
        if (FlowQuestionStatus::Pending !== $question->status) {
            return $this->outcome($question, $config, $context);
        }

        if (null === $question->external_message_id) {
            $failure = $this->send($question, $buttons, $config, $state, $context);

            if (null !== $failure) {
                $this->questions->close($question->id);

                return $failure;
            }
        }

        return NodeExecutionResult::delayed(
            stateChanges: [$this->markerKeyFor($context) => $question->id],
            metadata: [
                'question_id'             => $question->id,
                'addressee'               => $question->contact_id,
                'expires_at'              => $question->expires_at->toIso8601String(),
                'clamped_by_run_deadline' => $clamped,
            ],
            resumeAt: $question->expires_at->toImmutable(),
        );
    }

    /**
     * Woken (by the answer's job or the deadline): what the question says.
     *
     * @param  array<string, mixed>  $config
     */
    private function wake(string $questionId, array $config, NodeExecutionContext $context): NodeExecutionResult
    {
        $question = $this->questions->find($questionId);

        if (! $question instanceof FlowQuestion || $question->session_id !== $context->sessionId || $question->node_id !== $context->nodeId) {
            return $this->lost($questionId, $context);
        }

        return $this->outcome($question, $config, $context);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function outcome(FlowQuestion $question, array $config, NodeExecutionContext $context): NodeExecutionResult
    {
        if (FlowQuestionStatus::Pending === $question->status) {
            if ($question->expires_at->isFuture()) {
                // Woken early (clock skew, a stray job): keep waiting until the question's own deadline.
                return NodeExecutionResult::delayed(
                    stateChanges: [$this->markerKeyFor($context) => $question->id],
                    metadata: ['question_id' => $question->id],
                    resumeAt: $question->expires_at->toImmutable(),
                );
            }

            if (! $this->questions->expire($question->id)) {
                // Lost the race to an answer (or a close): read what won.
                $question = $this->questions->find($question->id);

                if (! $question instanceof FlowQuestion || FlowQuestionStatus::Pending === $question->status) {
                    return $this->lost((string) $question?->id, $context);
                }

                return $this->outcome($question, $config, $context);
            }

            $question->status = FlowQuestionStatus::Expired;
        }

        return match ($question->status) {
            FlowQuestionStatus::Answered => $this->answered($question, $config, $context),
            FlowQuestionStatus::Expired  => $this->timedOut($question, $context),
            default                      => $this->lost($question->id, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function answered(FlowQuestion $question, array $config, NodeExecutionContext $context): NodeExecutionResult
    {
        $value        = (string) $question->answerValue();
        $stateChanges = [$this->markerKeyFor($context) => null];
        $variable     = is_array($config['save_to_variable'] ?? null) ? Variable::tryFromArray($config['save_to_variable']) : null;

        if ($variable instanceof Variable) {
            $path = $this->variables->resolveTargetPath($variable);

            if (VariableStorage::Contact === $variable->storage) {
                // A contact variable is the run's contact; a contactless flow cannot publish one (ContactContextChecker).
                if ($context->contactWriter instanceof ContactWriterInterface && null !== $context->contactId) {
                    $context->contactWriter->write($path, $value);
                }
            } else {
                $stateChanges[$path] = $value;
            }
        }

        return NodeExecutionResult::executed(
            sourceHandle: (string) $question->answer_button_id,
            stateChanges: $stateChanges,
            metadata: [
                'question_id' => $question->id,
                'answer'      => $value,
                'button_id'   => $question->answer_button_id,
                'answered_by' => $question->contact_id,
                'answered_at' => $question->answered_at?->toIso8601String(),
            ],
        );
    }

    private function timedOut(FlowQuestion $question, NodeExecutionContext $context): NodeExecutionResult
    {
        if (null !== $question->external_message_id) {
            $this->keyboardEditor->removeKeyboard($question->tenant_id, $question->contact_id, $question->session_id, $question->external_message_id);
        }

        return NodeExecutionResult::executed(
            sourceHandle: self::TIMEOUT,
            stateChanges: [$this->markerKeyFor($context) => null],
            metadata: ['question_id' => $question->id, 'timed_out_at' => CarbonImmutable::now()->toIso8601String()],
        );
    }

    private function lost(string $questionId, NodeExecutionContext $context): NodeExecutionResult
    {
        $this->logger->warning('flow.ask_person.question_lost', [
            'session_id'  => $context->sessionId,
            'node_id'     => $context->nodeId,
            'question_id' => $questionId,
        ]);

        return $this->error('question_lost', 'the question this node waited for is gone or was closed.', [$this->markerKeyFor($context) => null]);
    }

    /**
     * Sends the question; null when it went out, else the node's `error` result.
     *
     * @param  list<array{id: string, label: mixed, value: string}>  $buttons
     * @param  array<string, mixed>                                 $config
     * @param  array<string, mixed>                                 $state
     */
    private function send(FlowQuestion $question, array $buttons, array $config, array $state, NodeExecutionContext $context): ?NodeExecutionResult
    {
        $keyboard = [];

        foreach ($buttons as $index => $button) {
            $keyboard[] = [
                'id'               => $button['id'],
                'label'            => $this->text($button['label'], $state, $context) ?? (string) $button['value'],
                'row'              => $index,
                'order'            => 0,
                'callback_payload' => FlowQuestionButton::encode($question->token, $index),
            ];
        }

        try {
            $messageId = $this->sender->send(
                tenantId: $context->tenantId,
                contactId: $question->contact_id,
                sessionId: $context->sessionId,
                payload: [
                    'content_type'    => SendMessageContentType::TextWithKeyboard->value,
                    'text'            => $this->text($config['text'] ?? null, $state, $context) ?? '',
                    'keyboard_mode'   => KeyboardMode::Inline->value,
                    'buttons'         => $keyboard,
                    'session_id'      => $context->sessionId,
                    'node_id'         => $context->nodeId,
                    'idempotency_key' => "question:{$question->id}",
                ],
            );
        } catch (VolumeLimitReachedException $exception) {
            return $this->error('limit_reached', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->warning('flow.ask_person.send_failed', [
                'tenant_id'   => $context->tenantId,
                'session_id'  => $context->sessionId,
                'node_id'     => $context->nodeId,
                'question_id' => $question->id,
                'exception'   => $exception->getMessage(),
            ]);

            return $this->error('send_failure', $exception->getMessage());
        }

        $this->questions->attachMessage($question->id, $messageId);

        return null;
    }

    /**
     * The node's buttons with a uuid id, in order; empty when one has none.
     *
     * @param  array<string, mixed>  $config
     *
     * @return list<array{id: string, label: mixed, value: string}>
     */
    private function buttons(array $config): array
    {
        $buttons = [];

        foreach (is_array($config['buttons'] ?? null) ? array_values($config['buttons']) : [] as $button) {
            // Kept as authored: the id is the branch's source handle, which the engine matches to an edge exactly.
            $id = is_array($button) && is_string($button['id'] ?? null) ? $button['id'] : '';

            if (! Uuid::isValid($id)) {
                return [];
            }

            $buttons[] = [
                'id'    => $id,
                'label' => $button['label'] ?? '',
                'value' => is_scalar($button['value'] ?? null) ? (string) $button['value'] : '',
            ];
        }

        return $buttons;
    }

    /**
     * The text a press after the decision gets, frozen now in the run's language: the node's, or the system one.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function lateAnswerText(array $config, array $state, NodeExecutionContext $context): string
    {
        return $this->text($config['late_answer_text'] ?? null, $state, $context)
            ?? $this->translator->translate(self::ALREADY_DECIDED_KEY, $context->resolvedLanguage);
    }

    /**
     * A text for the addressee in the run's language, with the flow's variables filled in; null when empty.
     *
     * @param  array<string, mixed>  $state
     */
    private function text(mixed $field, array $state, NodeExecutionContext $context): ?string
    {
        if (! is_string($field) && ! is_array($field)) {
            return null;
        }

        $resolved = $this->translator->resolveField($field, $context->resolvedLanguage);
        $rendered = $this->templates->render($resolved, $context, $state);

        return is_string($rendered) && '' !== mb_trim($rendered) ? $rendered : null;
    }

    private function hasText(mixed $value): bool
    {
        if (is_string($value)) {
            return '' !== mb_trim($value);
        }

        if (is_array($value)) {
            foreach ($value as $translation) {
                if (is_string($translation) && '' !== mb_trim($translation)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function timeoutSeconds(array $config): int
    {
        $seconds = $config['timeout_seconds'] ?? null;

        return is_numeric($seconds)
            ? min(self::MAX_TIMEOUT_SECONDS, max(self::MIN_TIMEOUT_SECONDS, (int) $seconds))
            : self::MIN_TIMEOUT_SECONDS;
    }

    /**
     * When the run is failed if still live: an automation's deadline, null for a conversation.
     */
    private function runDeadline(FlowSession $session): ?CarbonImmutable
    {
        if (null !== $session->deadline_at) {
            return $session->deadline_at->toImmutable();
        }

        if (FlowRunKind::Automation === $session->run_kind && null !== $session->created_at) {
            return $this->deadline->for($session->created_at);
        }

        return null;
    }

    private function markerKeyFor(NodeExecutionContext $context): string
    {
        return self::markerKey($context->nodeId);
    }

    /**
     * @param  array<string, mixed>  $stateChanges
     */
    private function error(string $type, string $message, array $stateChanges = []): NodeExecutionResult
    {
        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: self::ERROR,
            stateChanges: $stateChanges,
            metadata: ['error' => $message, 'error_type' => $type],
            errorMessage: "{$type}: {$message}",
        );
    }
}
