<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Auth\AuthCodeCheck;
use App\Domains\Flow\Auth\AuthCodeStore;
use App\Domains\Flow\Auth\AuthDestination;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Enums\AuthMethod;
use App\Domains\Flow\Enums\BranchOperator;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\Handlers\Support\OperatorComparator;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\Support\UsageUnitKey;
use App\Jobs\Flow\SendOneTimeCodeJob;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderRegistryInterface;
use Fapost\Foundation\Auth\Enums\OneTimeCodeChannel;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\NumberField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\StatePickerField;
use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * `auth_request@2` — proves who the contact is and branches on the result.
 *
 * Methods ({@see AuthMethod}):
 *  - `basic` compares a variable with an expected value, as `@1` does, without waiting;
 *  - `phone` asks the contact to share their own phone number (a reply button); a forwarded card of somebody else
 *    or a typed text is refused, and an `expected_phone`, when set, must match in E.164 form;
 *  - `sms_code` and `email_code` send a one-time code through the sender registered for the channel and wait for
 *    the contact to type it back; the contact may ask for a new one (an inline button) after a cooldown, up to a
 *    number of sends.
 *
 * Outputs: `authenticated` raises {@code contact.is_authenticated}; `failed` says why in `metadata.reason`. The
 * code itself never leaves {@see AuthCodeStore} and the job that delivers it: the state keeps only counters under
 * `system.auth.<node>` (no other node type may write `system.*`, so a flow cannot reset them), and the metadata
 * carries the method, outcome, reason, attempts and a masked destination.
 *
 * `@1` stays registered unchanged for the nodes published with it.
 */
final class AuthRequestV2NodeHandler extends AbstractVersionedHandler implements NodeConfigValidatorInterface
{
    final public const string TYPE = 'auth_request';

    final public const string AUTHENTICATED = 'authenticated';

    final public const string FAILED = 'failed';

    /** Per-period limit keys of the codes sent, one per channel. */
    final public const string SMS_LIMIT_KEY = 'auth_code_sms';

    final public const string EMAIL_LIMIT_KEY = 'auth_code_email';


    /** @var array<string, array{0: int, 1: int, 2: int}> field => [min, max, default] */
    final public const array BOUNDS = [
        'code_length'             => [4, 8, 6],
        'ttl_minutes'             => [1, 30, 10],
        'max_attempts'            => [1, 10, 5],
        'max_sends'               => [1, 5, 3],
        'resend_cooldown_seconds' => [30, 600, 60],
    ];

    private const int PHONE_DEFAULT_ATTEMPTS = 3;

    private const string SMS_DEFAULT_DESTINATION = '{{contact.phone}}';

    public function __construct(
        private readonly OperandResolver $operands,
        private readonly OperatorComparator $comparator,
        private readonly TemplateRenderer $templates,
        private readonly ContentTranslatorInterface $translator,
        private readonly MessageSenderInterface $sender,
        private readonly AuthCodeStore $codes,
        private readonly OneTimeCodeSenderRegistryInterface $senders,
        private readonly PeriodQuota $quota,
        private readonly Dispatcher $dispatcher,
    ) {
    }

    /**
     * The "send a new code" inline button of a node: a UUID (callback data carries the id as one) derived from the
     * node id, so a stale button of another `auth_request` node does not send this node's code.
     */
    public static function resendButtonId(string $nodeId): string
    {
        $hash = md5('auth_request:resend:' . $nodeId);

        return sprintf('%s-%s-%s-%s-%s', mb_substr($hash, 0, 8), mb_substr($hash, 8, 4), mb_substr($hash, 12, 4), mb_substr($hash, 16, 4), mb_substr($hash, 20, 12));
    }

    public function version(): int
    {
        return 2;
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
        $basic   = ['field' => 'method', 'op' => 'equals', 'value' => AuthMethod::Basic->value];
        $waiting = ['field' => 'method', 'op' => 'in', 'value' => AuthMethod::waitingValues()];
        $codes   = ['field' => 'method', 'op' => 'in', 'value' => [AuthMethod::SmsCode->value, AuthMethod::EmailCode->value]];
        $phone   = ['field' => 'method', 'op' => 'equals', 'value' => AuthMethod::Phone->value];

        return Schema::make()
            ->required(['method'])
            ->defaultConfig(['method' => AuthMethod::Basic->value])
            ->section(
                Section::make('challenge', (string) __('builder.nodes.auth_request.section'))
                    ->icon('shield-check')
                    ->fields([
                        SelectField::make('method')
                            ->label((string) __('builder.nodes.auth_request.method'))
                            ->required()
                            ->default(AuthMethod::Basic->value)
                            ->options(AuthMethod::options(2)),
                        StatePickerField::make('variable')
                            ->label((string) __('builder.nodes.auth_request.variable'))
                            ->placeholder('flow.code')
                            ->visibleWhen([$basic]),
                        SelectField::make('operator')
                            ->label((string) __('builder.nodes.auth_request.operator'))
                            ->default(BranchOperator::Eq->value)
                            ->options(BranchOperator::options())
                            ->visibleWhen([$basic]),
                        TextField::make('value')
                            ->label((string) __('builder.nodes.auth_request.value'))
                            ->placeholder('1234')
                            ->visibleWhen([$basic]),
                        TextareaField::make('prompt')
                            ->label((string) __('builder.nodes.auth_request.prompt'))
                            ->visibleWhen([$waiting]),
                        TextField::make('button_label')
                            ->label((string) __('builder.nodes.auth_request.button_label'))
                            ->visibleWhen([$phone]),
                        TextField::make('expected_phone')
                            ->label((string) __('builder.nodes.auth_request.expected_phone'))
                            ->help((string) __('builder.nodes.auth_request.expected_phone_hint'))
                            ->visibleWhen([$phone]),
                        TextField::make('destination')
                            ->label((string) __('builder.nodes.auth_request.destination'))
                            ->visibleWhen([$codes]),
                        NumberField::make('code_length')
                            ->label((string) __('builder.nodes.auth_request.code_length'))
                            ->min(self::BOUNDS['code_length'][0])->max(self::BOUNDS['code_length'][1])
                            ->default(self::BOUNDS['code_length'][2])
                            ->visibleWhen([$codes]),
                        NumberField::make('ttl_minutes')
                            ->label((string) __('builder.nodes.auth_request.ttl_minutes'))
                            ->min(self::BOUNDS['ttl_minutes'][0])->max(self::BOUNDS['ttl_minutes'][1])
                            ->default(self::BOUNDS['ttl_minutes'][2])
                            ->visibleWhen([$codes]),
                        NumberField::make('max_attempts')
                            ->label((string) __('builder.nodes.auth_request.max_attempts'))
                            ->min(self::BOUNDS['max_attempts'][0])->max(self::BOUNDS['max_attempts'][1])
                            ->visibleWhen([$waiting]),
                        NumberField::make('max_sends')
                            ->label((string) __('builder.nodes.auth_request.max_sends'))
                            ->min(self::BOUNDS['max_sends'][0])->max(self::BOUNDS['max_sends'][1])
                            ->default(self::BOUNDS['max_sends'][2])
                            ->visibleWhen([$codes]),
                        NumberField::make('resend_cooldown_seconds')
                            ->label((string) __('builder.nodes.auth_request.resend_cooldown_seconds'))
                            ->min(self::BOUNDS['resend_cooldown_seconds'][0])->max(self::BOUNDS['resend_cooldown_seconds'][1])
                            ->default(self::BOUNDS['resend_cooldown_seconds'][2])
                            ->visibleWhen([$codes]),
                        TextField::make('resend_label')
                            ->label((string) __('builder.nodes.auth_request.resend_label'))
                            ->visibleWhen([$codes]),
                        TextareaField::make('on_invalid_message')
                            ->label((string) __('builder.nodes.auth_request.on_invalid_message'))
                            ->visibleWhen([$waiting]),
                    ]),
            )
            ->toArray();
    }

    /**
     * Publish-time checks: a waiting method needs a prompt, an email code an address, a code method a sender for its
     * channel, and the limits must be within their bounds. A destination built from `flow.*` (what the contact
     * typed) is allowed but warned about: it lets a contact have codes sent to any address, limited only by the
     * per-destination cap.
     *
     * @param  array<string, mixed>  $node
     *
     * @return list<NodeConfigIssue>
     */
    public function validate(array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $method = AuthMethod::tryFrom(is_string($config['method'] ?? null) ? $config['method'] : '');

        if (null === $method) {
            return [NodeConfigIssue::error('auth_method_unknown', 'Choose an authentication method.', 'method')];
        }

        if (AuthMethod::Basic === $method) {
            return [];
        }

        $issues = [];

        if (! $this->hasText($config['prompt'] ?? null)) {
            $issues[] = NodeConfigIssue::error('auth_prompt_required', 'Write the message that asks the contact to confirm.', 'prompt');
        }

        if (AuthMethod::Phone === $method && ! $this->hasText($config['button_label'] ?? null)) {
            $issues[] = NodeConfigIssue::error('auth_button_label_required', 'Name the button that shares the phone number.', 'button_label');
        }

        foreach (self::BOUNDS as $field => [$min, $max]) {
            $raw = $config[$field] ?? null;

            if (null === $raw || '' === $raw) {
                continue;
            }

            if (! is_numeric($raw) || (int) $raw < $min || (int) $raw > $max) {
                $issues[] = NodeConfigIssue::error('auth_limit_out_of_range', sprintf('%s must be between %d and %d.', $field, $min, $max), $field);
            }
        }

        $channel = $method->codeChannel();

        if (null === $channel) {
            return $issues;
        }

        if (null === $this->senders->find($channel)) {
            $issues[] = NodeConfigIssue::error(
                'auth_sender_missing',
                (string) __(self::channelRules($channel)['missing_sender']),
                'method',
            );
        }

        $destination = is_string($config['destination'] ?? null) ? mb_trim($config['destination']) : '';

        if (null === self::channelRules($channel)['default_destination'] && '' === $destination) {
            $issues[] = NodeConfigIssue::error('auth_destination_required', 'Set the email address the code is sent to.', 'destination');
        }

        if (str_contains($destination, 'flow.')) {
            $issues[] = NodeConfigIssue::warning(
                'auth_destination_from_flow',
                'The code goes to an address taken from flow variables, which the contact may have typed: anyone can have codes sent to any address, up to the hourly limit per address.',
                'destination',
            );
        }

        return $issues;
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $method = AuthMethod::tryFrom(is_string($config['method'] ?? null) ? $config['method'] : '') ?? AuthMethod::Basic;

        return match ($method) {
            AuthMethod::Basic                          => $this->executeBasic($config, $state, $context),
            AuthMethod::Phone                          => $this->executePhone($config, $state, $context),
            AuthMethod::SmsCode, AuthMethod::EmailCode => $this->executeCode($method, $config, $state, $context),
        };
    }

    /**
     * Everything the code channels differ in, kept in one place: a new channel is one more arm here. The
     * normaliser and the mask name methods of {@see AuthDestination}.
     *
     * @return array{default_destination: ?string, normalize: 'normalizePhone'|'normalizeEmail', mask: 'maskPhone'|'maskEmail', limit_key: string, missing_sender: string}
     */
    private static function channelRules(OneTimeCodeChannel $channel): array
    {
        return match ($channel) {
            OneTimeCodeChannel::Sms => [
                'default_destination' => self::SMS_DEFAULT_DESTINATION,
                'normalize'           => 'normalizePhone',
                'mask'                => 'maskPhone',
                'limit_key'           => self::SMS_LIMIT_KEY,
                'missing_sender'      => 'builder.nodes.auth_request.no_sms_sender',
            ],
            OneTimeCodeChannel::Email => [
                'default_destination' => null,
                'normalize'           => 'normalizeEmail',
                'mask'                => 'maskEmail',
                'limit_key'           => self::EMAIL_LIMIT_KEY,
                'missing_sender'      => 'builder.nodes.auth_request.no_email_sender',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeBasic(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $variablePath = is_string($config['variable'] ?? null) && '' !== $config['variable'] ? $config['variable'] : null;

        [$path, $value] = $this->operands->resolve($config, $variablePath, $state, $context);

        $operator = is_string($config['operator'] ?? null) ? $config['operator'] : BranchOperator::Eq->value;
        $expected = $this->templates->render($config['value'] ?? null, $context, $state);

        if ($this->comparator->matches($value, $operator, $expected)) {
            $this->raiseAuthFlag($context);

            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::AUTHENTICATED,
                logResolved: null !== $path ? [$path => $value] : [],
                metadata: $this->metadata(AuthMethod::Basic, self::AUTHENTICATED),
            );
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: self::FAILED,
            logResolved: null !== $path ? [$path => $value] : [],
            metadata: $this->metadata(AuthMethod::Basic, self::FAILED, 'mismatch'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executePhone(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $markerKey = $this->markerKey($context);
        $marker    = $this->marker($state, $context);

        // First pass: ask for the number. An incoming message that brought the flow here is not an answer.
        if (null === $marker) {
            $this->sendPhonePrompt($config, $context, $this->text($config['prompt'] ?? null, $context));

            return new NodeExecutionResult(
                status: NodeExecutionStatus::Waiting,
                stateChanges: [$markerKey => $this->newChallenge($state, $context)],
            );
        }

        $incoming = $context->incoming;

        if (null === $incoming) {
            return NodeExecutionResult::waiting();
        }

        $reason = 'contact_required';
        $phone  = null;

        if (IncomingMessageType::Contact === $incoming->type) {
            $card  = is_array($incoming->payload['contact'] ?? null) ? $incoming->payload['contact'] : [];
            $own   = is_string($card['user_id'] ?? null) && '' !== $incoming->externalUserId && $card['user_id'] === $incoming->externalUserId;
            $phone = $own ? AuthDestination::normalizePhone(is_string($card['phone_number'] ?? null) ? $card['phone_number'] : null) : null;

            $reason = $own ? 'phone_invalid' : 'foreign_contact';
        }

        if (null !== $phone) {
            // A configured expected number that renders empty or invalid (a CRM lookup that failed) matches nothing.
            $configured = is_string($config['expected_phone'] ?? null) && '' !== mb_trim($config['expected_phone']);
            $expected   = $this->templates->render($config['expected_phone'] ?? null, $context, $state);
            $expected   = is_scalar($expected) ? AuthDestination::normalizePhone((string) $expected) : null;

            if (! $configured || (null !== $expected && $expected === $phone)) {
                $this->raiseAuthFlag($context);

                return new NodeExecutionResult(
                    status: NodeExecutionStatus::Executed,
                    sourceHandle: self::AUTHENTICATED,
                    stateChanges: [$markerKey => $this->ended($marker)],
                    metadata: $this->metadata(AuthMethod::Phone, self::AUTHENTICATED, null, (int) $marker['attempts'], AuthDestination::maskPhone($phone)),
                );
            }

            $reason = 'phone_mismatch';
        }

        $attempts    = (int) $marker['attempts'] + 1;
        $maxAttempts = $this->bounded($config, 'max_attempts', self::PHONE_DEFAULT_ATTEMPTS);

        if ($attempts >= $maxAttempts) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::FAILED,
                stateChanges: [$markerKey => $this->ended($marker)],
                metadata: $this->metadata(AuthMethod::Phone, self::FAILED, 'attempts_exhausted', $attempts, null, $reason),
            );
        }

        // Ask again with the button: the hint when the author wrote one, otherwise the prompt.
        $hint = $this->text($config['on_invalid_message'] ?? null, $context);
        $this->sendPhonePrompt($config, $context, '' !== $hint ? $hint : $this->text($config['prompt'] ?? null, $context));

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            stateChanges: [$markerKey => [...$marker, 'attempts' => $attempts]],
            metadata: $this->metadata(AuthMethod::Phone, 'waiting', $reason, $attempts),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeCode(AuthMethod $method, array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $markerKey = $this->markerKey($context);
        $marker    = $this->marker($state, $context);

        // First pass: send the first code. An incoming message that brought the flow here is not an answer.
        if (null === $marker) {
            return $this->issueCode($method, $config, $state, $context, $this->newChallenge($state, $context));
        }

        $incoming = $context->incoming;

        if (null === $incoming) {
            return NodeExecutionResult::waiting();
        }

        if ($this->isResendPress($incoming->type, $incoming->text, $context)) {
            if ((int) $marker['sends'] >= $this->bounded($config, 'max_sends')) {
                return $this->fail($method, $context, $marker, 'rate_limited', (int) $marker['attempts'], 'max_sends');
            }

            $cooldown = $this->bounded($config, 'resend_cooldown_seconds');

            if (CarbonImmutable::now()->getTimestamp() - (int) $marker['last_sent_at'] < $cooldown) {
                // Too early: ignore the press, the pending code still works.
                return new NodeExecutionResult(
                    status: NodeExecutionStatus::Waiting,
                    metadata: $this->metadata($method, 'waiting', 'cooldown', (int) $marker['attempts']),
                );
            }

            return $this->issueCode($method, $config, $state, $context, $marker);
        }

        // Only a typed answer is an attempt: a photo, a sticker or a stale button of another node is not.
        if (IncomingMessageType::Text !== $incoming->type) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Waiting,
                metadata: $this->metadata($method, 'waiting', 'not_a_code', (int) $marker['attempts']),
            );
        }

        $typed = preg_replace('/\D+/', '', (string) $incoming->text) ?? '';
        $check = '' === $typed
            ? ($this->codes->deliveryFailed($context->tenantId, $context->sessionId, $context->nodeId) ? AuthCodeCheck::DeliveryFailed : AuthCodeCheck::Mismatch)
            : $this->codes->check($context->tenantId, $context->sessionId, $context->nodeId, $typed);
        $tried = (int) $marker['attempts'];

        if (AuthCodeCheck::Match === $check) {
            // Marked used, not deleted: a step retried before its state was saved still finds the match, while a
            // later challenge replaces the record and the used code is never accepted by another one.
            $this->codes->markUsed($context->tenantId, $context->sessionId, $context->nodeId);
            $this->raiseAuthFlag($context);

            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::AUTHENTICATED,
                stateChanges: [$markerKey => $this->ended($marker)],
                metadata: $this->metadata($method, self::AUTHENTICATED, null, $tried + 1),
            );
        }

        if (AuthCodeCheck::Missing === $check) {
            return $this->fail($method, $context, $marker, 'expired', $tried);
        }

        if (AuthCodeCheck::DeliveryFailed === $check) {
            return $this->fail($method, $context, $marker, 'delivery_failed', $tried);
        }

        $attempts = $tried + 1;

        if ($attempts >= $this->bounded($config, 'max_attempts')) {
            return $this->fail($method, $context, $marker, 'attempts_exhausted', $attempts, 'wrong_code');
        }

        $hint = $this->text($config['on_invalid_message'] ?? null, $context);

        if ('' !== $hint) {
            $this->sendText($context, $hint, 'hint:');
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            stateChanges: [$markerKey => [...$marker, 'attempts' => $attempts]],
            metadata: $this->metadata($method, 'waiting', 'wrong_code', $attempts),
        );
    }

    /**
     * Send code number `sends + 1`: check the destination, the sender, the per-destination cap and the tenant's
     * quota, keep the code's hash, queue its delivery, then prompt the contact. A step retried after the code was
     * issued keeps the code already on its way and queues nothing new.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     * @param  array{active: true, challenge: int, sends: int, attempts: int, last_sent_at: int}  $marker
     */
    private function issueCode(AuthMethod $method, array $config, array $state, NodeExecutionContext $context, array $marker): NodeExecutionResult
    {
        $channel = $method->codeChannel() ?? OneTimeCodeChannel::Email;
        $rules   = self::channelRules($channel);

        $rendered = $this->templates->render(
            $config['destination'] ?? $rules['default_destination'],
            $context,
            $state,
        );
        $rendered    = is_scalar($rendered) ? (string) $rendered : null;
        $destination = AuthDestination::{$rules['normalize']}($rendered);

        if (null === $destination) {
            return $this->fail($method, $context, $marker, 'invalid_destination', $marker['attempts']);
        }

        $masked = AuthDestination::{$rules['mask']}($destination);

        if (null === $this->senders->find($channel)) {
            return $this->fail($method, $context, $marker, 'no_sender', $marker['attempts'], null, $masked);
        }

        $sendNumber = $marker['sends'] + 1;
        $sendToken  = $marker['challenge'] . ':' . $sendNumber;
        $ttlMinutes = $this->bounded($config, 'ttl_minutes');

        // A step retried after this send was issued keeps the code on its way: no new code, count or quota unit.
        if (! $this->codes->isIssued($context->tenantId, $context->sessionId, $context->nodeId, $sendToken)) {
            if (! $this->codes->destinationHasRoom($context->tenantId, $destination)) {
                return $this->fail($method, $context, $marker, 'rate_limited', $marker['attempts'], 'destination', $masked);
            }

            $decision = $this->quota->consume(
                $rules['limit_key'],
                UsageUnitKey::make('auth_code:', "{$context->sessionId}:{$context->nodeId}:{$sendToken}"),
                CarbonImmutable::now(),
                RefusedWork::Other,
            );

            if (! $decision->allowed) {
                return $this->fail($method, $context, $marker, 'rate_limited', $marker['attempts'], 'quota', $masked);
            }

            if (! $this->codes->allowDestination($context->tenantId, $destination)) {
                return $this->fail($method, $context, $marker, 'rate_limited', $marker['attempts'], 'destination', $masked);
            }

            $code = $this->generateCode($this->bounded($config, 'code_length'));

            $this->codes->issue($context->tenantId, $context->sessionId, $context->nodeId, $sendToken, $code, $ttlMinutes);

            $this->dispatcher->dispatch(new SendOneTimeCodeJob(
                tenantId: $context->tenantId,
                sessionId: $context->sessionId,
                nodeId: $context->nodeId,
                sendToken: $sendToken,
                channel: $channel->value,
                destination: $destination,
                code: $code,
                locale: $context->resolvedLanguage,
                expiresInMinutes: $ttlMinutes,
            ));
        }

        $this->sendCodePrompt($config, $context, $sendNumber < $this->bounded($config, 'max_sends'));

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            stateChanges: [$this->markerKey($context) => [
                ...$marker,
                'sends'        => $sendNumber,
                'last_sent_at' => CarbonImmutable::now()->getTimestamp(),
            ]],
            metadata: $this->metadata($method, 'waiting', null, $marker['attempts'], $masked) + ['sends' => $sendNumber],
        );
    }

    /**
     * @param  array{active: true, challenge: int, sends: int, attempts: int, last_sent_at: int}  $marker
     */
    private function fail(
        AuthMethod $method,
        NodeExecutionContext $context,
        array $marker,
        string $reason,
        int $attempts,
        ?string $detail = null,
        ?string $masked = null,
    ): NodeExecutionResult {
        $this->codes->forget($context->tenantId, $context->sessionId, $context->nodeId);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: self::FAILED,
            stateChanges: [$this->markerKey($context) => $this->ended($marker)],
            metadata: $this->metadata($method, self::FAILED, $reason, $attempts, $masked, $detail),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(
        AuthMethod $method,
        string $outcome,
        ?string $reason = null,
        ?int $attempts = null,
        ?string $maskedDestination = null,
        ?string $detail = null,
    ): array {
        return array_filter([
            'method'             => $method->value,
            'outcome'            => $outcome,
            'reason'             => $reason,
            'detail'             => $detail,
            'attempts'           => $attempts,
            'destination_masked' => $maskedDestination,
        ], static fn (mixed $value): bool => null !== $value);
    }

    private function isResendPress(IncomingMessageType $type, ?string $text, NodeExecutionContext $context): bool
    {
        if (IncomingMessageType::CallbackQuery !== $type) {
            return false;
        }

        $decoded = CallbackDataCodec::decode($text);

        return null !== $decoded
            && self::resendButtonId($context->nodeId) === mb_strtolower($decoded['button_id'])
            && mb_strtolower($decoded['session_id']) === mb_strtolower($context->sessionId);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function sendPhonePrompt(array $config, NodeExecutionContext $context, string $text): void
    {
        // The label is required at publish; a translation missing at run time falls back to the prompt's text.
        $label = $this->text($config['button_label'] ?? null, $context);
        $label = '' !== $label ? $label : $text;

        $this->sender->send(
            tenantId: $context->tenantId,
            contactId: (string) $context->contactId,
            sessionId: $context->sessionId,
            payload: [
                'content_type' => 'text_with_keyboard',
                'text'         => '' !== $text ? $text : $label,
                'buttons'      => [[
                    'id'      => 'auth-share-contact',
                    'type'    => 'reply',
                    'label'   => $label,
                    'special' => 'request_contact',
                    'row'     => 0,
                    'order'   => 0,
                ]],
                'keyboard_mode'   => 'reply',
                'session_id'      => $context->sessionId,
                'node_id'         => $context->nodeId,
                'idempotency_key' => $context->idempotencyKey,
            ],
        );
    }

    /**
     * The prompt, with the "send a new code" button while more sends are allowed and the author named it. The
     * prompt never carries the code.
     *
     * @param  array<string, mixed>  $config
     */
    private function sendCodePrompt(array $config, NodeExecutionContext $context, bool $canResend): void
    {
        $text  = $this->text($config['prompt'] ?? null, $context);
        $label = $this->text($config['resend_label'] ?? null, $context);

        if ('' === $text) {
            return;
        }

        if (! $canResend || '' === $label) {
            $this->sendText($context, $text);

            return;
        }

        $this->sender->send(
            tenantId: $context->tenantId,
            contactId: (string) $context->contactId,
            sessionId: $context->sessionId,
            payload: [
                'content_type' => 'text_with_keyboard',
                'text'         => $text,
                'buttons'      => [[
                    'id'    => self::resendButtonId($context->nodeId),
                    'type'  => 'callback',
                    'label' => $label,
                    'row'   => 0,
                    'order' => 0,
                ]],
                'keyboard_mode'   => 'inline',
                'session_id'      => $context->sessionId,
                'node_id'         => $context->nodeId,
                'idempotency_key' => $context->idempotencyKey,
            ],
        );
    }

    /**
     * @param  string  $keyPrefix  set apart the delivery key of a message that is not the step's prompt (the hint)
     */
    private function sendText(NodeExecutionContext $context, string $text, string $keyPrefix = ''): void
    {
        $this->sender->send(
            tenantId: $context->tenantId,
            contactId: (string) $context->contactId,
            sessionId: $context->sessionId,
            payload: [
                'content_type'    => 'text',
                'text'            => $text,
                'node_id'         => $context->nodeId,
                'idempotency_key' => $keyPrefix . $context->idempotencyKey,
            ],
        );
    }

    private function text(mixed $field, NodeExecutionContext $context): string
    {
        return is_string($field) || is_array($field)
            ? mb_trim($this->translator->resolveField($field, $context->resolvedLanguage))
            : '';
    }

    private function hasText(mixed $field): bool
    {
        if (is_string($field)) {
            return '' !== mb_trim($field);
        }

        if (is_array($field)) {
            foreach ($field as $value) {
                if (is_string($value) && '' !== mb_trim($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A limit from the config, clamped to its bounds; the default when unset.
     *
     * @param  array<string, mixed>  $config
     */
    private function bounded(array $config, string $field, ?int $default = null): int
    {
        [$min, $max, $fallback] = self::BOUNDS[$field];

        $raw = $config[$field] ?? null;

        if (! is_numeric($raw)) {
            return $default ?? $fallback;
        }

        return max($min, min($max, (int) $raw));
    }

    private function generateCode(int $length): string
    {
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    private function markerKey(NodeExecutionContext $context): string
    {
        return SystemStateKeys::AUTH_REQUEST_PREFIX . '.' . $context->nodeId;
    }

    /**
     * The challenge in progress: `active` until the node exits. An exited node keeps only the number of its last
     * challenge, so entering it again (a loop, a `failed` edge leading back) starts challenge `n + 1`, whose sends
     * and job guards are its own; a step retried before its state was saved starts the same challenge again.
     *
     * @param  array<string, mixed>  $state
     *
     * @return array{active: true, challenge: int, sends: int, attempts: int, last_sent_at: int}|null
     */
    private function marker(array $state, NodeExecutionContext $context): ?array
    {
        $marker = data_get($state, $this->markerKey($context));

        if (! is_array($marker) || true !== ($marker['active'] ?? false)) {
            return null;
        }

        return [
            'active'       => true,
            'challenge'    => (int) ($marker['challenge'] ?? 1),
            'sends'        => (int) ($marker['sends'] ?? 0),
            'attempts'     => (int) ($marker['attempts'] ?? 0),
            'last_sent_at' => (int) ($marker['last_sent_at'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     *
     * @return array{active: true, challenge: int, sends: int, attempts: int, last_sent_at: int}
     */
    private function newChallenge(array $state, NodeExecutionContext $context): array
    {
        $last = data_get($state, $this->markerKey($context) . '.challenge');

        return [
            'active'       => true,
            'challenge'    => (is_numeric($last) ? (int) $last : 0) + 1,
            'sends'        => 0,
            'attempts'     => 0,
            'last_sent_at' => 0,
        ];
    }

    /**
     * @param  array{challenge: int}  $marker
     *
     * @return array{challenge: int}
     */
    private function ended(array $marker): array
    {
        return ['challenge' => $marker['challenge']];
    }

    private function raiseAuthFlag(NodeExecutionContext $context): void
    {
        $writer = $context->contactWriter;

        if (null === $writer) {
            throw new InvalidNodeConfigException('auth_request: ContactWriter is unavailable in this NodeExecutionContext.');
        }

        $writer->write('contact.is_authenticated', true);
    }
}
