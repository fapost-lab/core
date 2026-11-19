<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Call\CallAttempt;
use App\Domains\Flow\Call\CallExecutionLedgerInterface;
use App\Domains\Flow\Call\CallOutcome;
use App\Domains\Flow\Call\CallRetryPolicy;
use App\Domains\Flow\Call\CallSuccessStatuses;
use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Call\Transports\HttpTransport;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\Support\UsageUnitKey;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialReference;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialNotFoundException;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Flow\Call\CallResult;
use Fapost\Foundation\Flow\Contracts\ContactWriterInterface;
use Fapost\Foundation\Flow\Contracts\DeclaresContextRequirementInterface;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\NodeContextRequirement;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\ObjectArrayField;
use Fapost\Support\Builder\Schema\Fields\ObjectField;
use Fapost\Support\Builder\Schema\Fields\SelectField;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Fields\ToggleField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;
use Illuminate\Support\Sleep;
use JsonException;
use stdClass;

/**
 * Outgoing integration node. Resolves a pluggable transport (http / handler)
 * from {@see CallTransportRegistry}, renders all `{{template}}` expressions in
 * target/parameters/options against the live session state, invokes the
 * transport, and routes the session through the `success` / `error` handle
 * based on {@see CallResult::$success}.
 *
 * Response handling is layered and both layers are optional:
 *  - `save_to_variable` writes the whole `{status, body, headers}` object so an
 *    external map (or the {@see AssignNodeHandler}) can extract fields later.
 *  - `result_mapping` extracts individual response paths (`body.data.id`,
 *    `status`, `headers.X`) straight into variables inline.
 *
 * Two optional fields tighten the outcome (both default to the earlier behaviour):
 *  - `success_statuses` replaces the transport's own verdict by a list of statuses ({@see CallSuccessStatuses}).
 *  - `strict_mapping` sends a successful call to `error` (`error_code = mapping_missing`) when a mapped path is absent
 *    from the response, instead of writing null.
 *
 * The optional `credential_id` names a saved credential of the tenant, applied by the `http` transport: the id is read
 * as it is, never through a template, so a flow variable cannot pick another credential. A credential that is missing,
 * cannot be read, or sits on a transport that cannot use one ends the node on `error` before any quota is spent.
 *
 * The optional `retry` sends a failed call again, with backoff, inside the node's window ({@see CallRetryPolicy}).
 *
 * Legacy nodes (no `transport`/`target`) keep their original behavior through
 * {@see executeLegacy()} — POST `url` with `include_state`, save body to
 * `save_response_to` / `save_to_variable`.
 *
 * It runs with a contact or without one: {@see CallContext::$contactId} (and the legacy payload's `contact_id`) is
 * `null` on a contactless run, and a config that reads the contact or saves to a contact variable is refused at
 * publish there.
 */
final class CallNodeHandler extends AbstractVersionedHandler implements DeclaresContextRequirementInterface, NodeConfigValidatorInterface
{
    final public const string TYPE = 'call';

    /**
     * Per-period limit key spent by every execution, before the transport is called.
     */
    final public const string LIMIT_KEY = 'call_executions';

    private const string ERROR_META           = 'error';
    private const string ERROR_TYPE_META      = 'error_type';
    private const string ERROR_CODE_META      = 'error_code';
    private const string IDEMPOTENCY_KEY_META = 'idempotency_key';
    private const string STATUS_CODE_META     = 'status_code';
    private const string ATTEMPT_META         = 'attempt_number';
    private const string DEDUPLICATED_META    = 'deduplicated';
    private const string MISSING_PATHS_META   = 'missing_paths';
    private const string SENDS_META           = 'attempts';
    private const string RETRY_STOPPED_META   = 'retry_stopped';

    private const string RETRY_STOPPED_WINDOW = 'window';

    private const string ERROR_MAPPING_MISSING = 'mapping_missing';

    private const string CREDENTIAL_ID_META = 'credential_id';

    private const string ERROR_CREDENTIAL_UNSUPPORTED = 'credential_unsupported';

    private const string ERROR_CREDENTIAL_NOT_FOUND = 'credential_not_found';

    private const string ERROR_CREDENTIAL_UNAVAILABLE = 'credential_unavailable';

    private const string ERROR_SOLUTION_INACTIVE = 'solution_inactive';

    private const string ERROR_SOLUTION_NOT_READY = 'solution_not_ready';

    private const string HANDLE_SUCCESS = 'success';
    private const string HANDLE_ERROR   = 'error';

    private const string DEFAULT_TRANSPORT = 'http';

    public function __construct(
        private readonly CallTransportRegistry $transports,
        private readonly TemplateRenderer $templates,
        private readonly VariableResolverInterface $variableResolver,
        private readonly PeriodQuota $quota,
        private readonly CallExecutionLedgerInterface $ledger,
        private readonly CredentialStoreInterface $credentials,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Integration';
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
            ->defaultConfig([
                'transport'         => self::DEFAULT_TRANSPORT,
                'target'            => '',
                'parameters'        => [],
                'transport_options' => ['timeout' => 10, 'success_when' => '2xx'],
                'result_mapping'    => [],
            ])
            ->section(
                Section::make('request', 'Request')
                    ->icon('globe-alt')
                    ->fields([
                        SelectField::make('transport')
                            ->label('Transport')
                            ->options(['http', 'handler'])
                            ->default(self::DEFAULT_TRANSPORT),
                        TextField::make('target')
                            ->label('Target')
                            ->placeholder('POST https://example.com/webhook'),
                        TextField::make('credential_id')
                            ->label('Saved credential'),
                    ]),
            )
            ->section(
                Section::make('response', 'Response handling')
                    ->icon('arrow-down-tray')
                    ->fields([
                        ObjectField::make('save_to_variable')
                            ->label('Save full response to')
                            ->fields([
                                TextField::make('name')->label('Name'),
                                SelectField::make('storage')
                                    ->label('Storage')
                                    ->options(['session', 'contact'])
                                    ->default('session'),
                                TextField::make('group')->label('Group'),
                            ]),
                        ObjectArrayField::make('result_mapping')
                            ->label('Field mapping')
                            ->itemFields([
                                TextField::make('from')->label('Response path'),
                            ]),
                        ToggleField::make('strict_mapping')
                            ->label('Fail when a mapped path is missing')
                            ->default(false),
                    ]),
            )
            ->toArray();
    }

    /**
     * Publish-time checks of the optional `success_statuses`, `strict_mapping` and `retry`; all are literals, never templates.
     *
     * @param  array<string, mixed>  $node
     *
     * @return list<NodeConfigIssue>
     */
    public function validate(array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $issues = [];

        $problem = CallSuccessStatuses::problem($config['success_statuses'] ?? null);
        $retry   = CallRetryPolicy::problem($config['retry'] ?? null);

        if (null !== $retry) {
            $issues[] = NodeConfigIssue::error('call_invalid_retry', $retry, 'retry');
        }

        if (null !== $problem) {
            $issues[] = NodeConfigIssue::error('call_invalid_success_statuses', $problem, 'success_statuses');
        }

        if (isset($config['strict_mapping']) && ! is_bool($config['strict_mapping'])) {
            $issues[] = NodeConfigIssue::error(
                'call_invalid_strict_mapping',
                'strict_mapping must be true or false.',
                'strict_mapping',
            );
        }

        return [...$issues, ...$this->credentialIssues($config)];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];

        // Legacy nodes predate the transport layer — detect by absence of the
        // new-shape keys and preserve their original request/response behavior.
        if (! isset($config['transport']) && ! isset($config['target'])) {
            return $this->executeLegacy($config, $state, $context);
        }

        $transportId = is_string($config['transport'] ?? null) && '' !== $config['transport']
            ? $config['transport']
            : self::DEFAULT_TRANSPORT;

        if (! $this->transports->has($transportId)) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::HANDLE_ERROR,
                metadata: [
                    self::ERROR_TYPE_META => 'unknown_transport',
                    self::ERROR_META      => $transportId,
                ],
            );
        }

        $renderedTarget = $this->templates->render($config['target'] ?? '', $context, $state);
        $target         = is_string($renderedTarget) ? $renderedTarget : '';

        $rawParameters = is_array($config['parameters'] ?? null) ? $config['parameters'] : [];
        $parameters    = $this->templates->render($rawParameters, $context, $state);
        $parameters    = is_array($parameters) ? $parameters : [];

        $rawOptions = is_array($config['transport_options'] ?? null) ? $config['transport_options'] : [];
        $options    = $this->templates->render($rawOptions, $context, $state);
        $options    = is_array($options) ? $options : [];

        $credentialId = is_string($config['credential_id'] ?? null) && '' !== $config['credential_id']
            ? $config['credential_id']
            : null;

        $callContext = $this->buildContext($context);
        $earlier     = $this->ledger->completed($callContext->tenantId, $callContext->idempotencyKey);
        $credential  = null;

        // A replay of a successful call sends nothing, so it needs no credential; any other run resolves it first, so
        // a refused credential costs no quota.
        if (null !== $credentialId && null === $earlier) {
            $credential = $this->resolveCredential($credentialId, $transportId);

            if ($credential instanceof NodeExecutionResult) {
                return $credential;
            }
        }

        $refused = $this->refuseWhenVolumeUsedUp($context, $earlier);

        if (null !== $refused) {
            return $refused;
        }

        $request = new CallRequest(target: $target, parameters: $parameters, options: $options, credential: $credential);
        $outcome = $this->dispatch(
            $transportId,
            $request,
            $callContext,
            $earlier,
            CallSuccessStatuses::fromConfig($config['success_statuses'] ?? null),
            CallRetryPolicy::fromConfig($config['retry'] ?? null),
        );
        $result = $outcome->result;

        $stateChanges = [];
        $missing      = $this->applyResponse($config, $result, $context, $stateChanges);
        $mappingFails = [] !== $missing;
        $errorCode    = $mappingFails ? self::ERROR_MAPPING_MISSING : $result->errorCode;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $result->success && ! $mappingFails ? self::HANDLE_SUCCESS : self::HANDLE_ERROR,
            stateChanges: $stateChanges,
            metadata: array_filter([
                self::STATUS_CODE_META     => $result->metadata['status_code'] ?? null,
                self::ERROR_CODE_META      => $errorCode,
                self::CREDENTIAL_ID_META   => $credentialId,
                self::MISSING_PATHS_META   => $mappingFails ? $missing : null,
                self::IDEMPOTENCY_KEY_META => $callContext->idempotencyKey,
                self::ATTEMPT_META         => $outcome->attempt,
                self::SENDS_META           => $outcome->sends > 1 ? $outcome->sends : null,
                self::RETRY_STOPPED_META   => $outcome->retryStopped ? self::RETRY_STOPPED_WINDOW : null,
                self::DEDUPLICATED_META    => $outcome->replayed ? true : null,
            ], static fn (mixed $v): bool => null !== $v),
            // The flow log keeps only this field; without it the author would not see why the call was refused.
            errorMessage: $mappingFails
                ? self::ERROR_MAPPING_MISSING . ': ' . implode(', ', $missing)
                : (in_array($result->errorCode, [self::ERROR_SOLUTION_INACTIVE, self::ERROR_SOLUTION_NOT_READY], true)
                    ? $result->errorCode . ': ' . (is_string($result->metadata['solution_id'] ?? null) ? $result->metadata['solution_id'] : 'unknown')
                    : null),
        );
    }

    /**
     * The `credential_id` must be a string that names an existing credential of the tenant, on the `http` transport.
     * Draft saving and publishing share this check: the validator does not tell them apart, so a problem blocks publishing.
     *
     * @param  array<string, mixed>  $config
     *
     * @return list<NodeConfigIssue>
     */
    private function credentialIssues(array $config): array
    {
        $id = $config['credential_id'] ?? null;

        if (null === $id || '' === $id) {
            return [];
        }

        if (! is_string($id)) {
            return [NodeConfigIssue::error('call_invalid_credential', 'credential_id must be the id of a saved credential.', 'credential_id')];
        }

        $transport = is_string($config['transport'] ?? null) && '' !== $config['transport'] ? $config['transport'] : self::DEFAULT_TRANSPORT;

        if (HttpTransport::ID !== $transport) {
            return [NodeConfigIssue::error(
                'call_credential_unsupported',
                'A saved credential can only be used with the http transport.',
                'credential_id',
            )];
        }

        $known = array_filter(
            $this->credentials->references(),
            static fn (CredentialReference $reference): bool => $reference->id === $id,
        );

        if ([] === $known) {
            return [NodeConfigIssue::error('call_credential_not_found', 'The saved credential does not exist.', 'credential_id')];
        }

        return [];
    }

    /**
     * Reads the credential the node names, or says why the node cannot use it.
     *
     * Only the `http` transport applies a credential. The refusal is the node's `error` handle with the code in its
     * metadata; the credential id is not secret and is kept there.
     */
    private function resolveCredential(string $id, string $transportId): CredentialSecret|NodeExecutionResult
    {
        if (HttpTransport::ID !== $transportId) {
            return $this->credentialRefusal(self::ERROR_CREDENTIAL_UNSUPPORTED, $id);
        }

        try {
            return $this->credentials->secret($id);
        } catch (CredentialNotFoundException) {
            return $this->credentialRefusal(self::ERROR_CREDENTIAL_NOT_FOUND, $id);
        } catch (CredentialUnavailableException) {
            return $this->credentialRefusal(self::ERROR_CREDENTIAL_UNAVAILABLE, $id);
        }
    }

    private function credentialRefusal(string $code, string $credentialId): NodeExecutionResult
    {
        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: self::HANDLE_ERROR,
            metadata: [
                self::ERROR_CODE_META    => $code,
                self::CREDENTIAL_ID_META => $credentialId,
            ],
            // The flow log keeps only this field, so the reason is visible there.
            errorMessage: $code . ': ' . $credentialId,
        );
    }

    /**
     * Write the whole-response variable and apply each result_mapping entry.
     *
     * With `strict_mapping` and a successful call, a mapped path that is absent from the response (a path whose value
     * is null is present) is returned and no mapping entry is written, so the node can take its `error` handle without
     * leaving half of the mapping behind. An error response is mapped leniently: it already takes `error`, and its
     * body rarely has the success shape.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $stateChanges
     *
     * @return list<string>  the missing `from` paths; empty when none or not strict
     */
    private function applyResponse(
        array $config,
        CallResult $result,
        NodeExecutionContext $context,
        array &$stateChanges,
    ): array {
        $payload  = $result->payload;
        $metadata = $result->metadata;

        // Addressable bag for result_mapping `from` paths — both friendly
        // (status/body/headers) and raw (payload/metadata) roots resolve.
        $bag = [
            'status'   => $metadata['status_code'] ?? null,
            'headers'  => $metadata['headers'] ?? [],
            'body'     => $payload,
            'payload'  => $payload,
            'metadata' => $metadata,
        ];

        $whole = [
            'status'  => $bag['status'],
            'body'    => $payload,
            'headers' => $bag['headers'],
        ];
        $this->writeVariable($this->resolveVariable($config['save_to_variable'] ?? null), $whole, $context, $stateChanges);

        $strict   = true === ($config['strict_mapping'] ?? null) && $result->success;
        $absent   = new stdClass();
        $mappings = is_array($config['result_mapping'] ?? null) ? $config['result_mapping'] : [];
        $writes   = [];
        $missing  = [];

        foreach ($mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $from = is_string($mapping['from'] ?? null) && '' !== $mapping['from'] ? $mapping['from'] : null;
            $to   = $this->resolveVariable($mapping['to'] ?? null);

            if (null === $from || ! $to instanceof Variable) {
                continue;
            }

            $value = data_get($bag, $from, $absent);

            if ($absent === $value) {
                if ($strict) {
                    $missing[] = $from;

                    continue;
                }

                // Lenient (the default): a missing path is written as null rather than failing the node. The
                // author opted into the mapping, an absent field is expected to surface as an empty variable.
                $value = null;
            }

            $writes[] = [$to, $value];
        }

        if ([] !== $missing) {
            return array_values(array_unique($missing));
        }

        foreach ($writes as [$to, $value]) {
            $this->writeVariable($to, $value, $context, $stateChanges);
        }

        return [];
    }

    /**
     * Legacy execution for nodes authored before the transport layer. Routes
     * through the http transport so the old `HttpClientInterface` can be retired,
     * preserving the original payload shape ({session_id, contact_id, state}) and
     * the `save_response_to` / `save_to_variable` (body-only) write surface.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeLegacy(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $url = $config['url'] ?? null;

        if (! is_string($url) || '' === $url) {
            throw new InvalidNodeConfigException('call: missing url');
        }

        $parameters = [];
        $rawHeaders = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        foreach ($rawHeaders as $headerKey => $headerValue) {
            if (! is_string($headerKey) || '' === $headerKey) {
                continue;
            }
            if (str_starts_with($headerKey, 'X-Idempotency-Key') || str_starts_with($headerKey, 'X-FaPost-')) {
                continue;
            }
            $parameters["headers.{$headerKey}"] = (string) $headerValue;
        }

        $payload = [
            'session_id' => $context->sessionId,
            'contact_id' => $context->contactId,
        ];
        $includeState = is_array($config['include_state'] ?? null) ? $config['include_state'] : [];
        foreach ($includeState as $path) {
            if (! is_string($path)) {
                continue;
            }
            $payload['state'][$path] = data_get($state, $path);
        }

        try {
            $bodyRaw = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidNodeConfigException('call: failed to encode legacy payload: ' . $exception->getMessage());
        }

        $request = new CallRequest(
            target: "POST {$url}",
            parameters: $parameters,
            options: [
                'timeout'  => (int) ($config['timeout'] ?? 10),
                'body_raw' => $bodyRaw,
            ],
        );

        $callContext = $this->buildContext($context);
        $earlier     = $this->ledger->completed($callContext->tenantId, $callContext->idempotencyKey);
        $refused     = $this->refuseWhenVolumeUsedUp($context, $earlier);

        if (null !== $refused) {
            return $refused;
        }

        $outcome = $this->dispatch(self::DEFAULT_TRANSPORT, $request, $callContext, $earlier);
        $result  = $outcome->result;

        $stateChanges = [];
        $variable     = $this->resolveVariable($config['save_to_variable'] ?? null);

        if ($variable instanceof Variable) {
            $this->writeVariable($variable, $result->payload, $context, $stateChanges);
        } else {
            $legacyPath = $config['save_response_to'] ?? null;
            if (is_string($legacyPath) && '' !== $legacyPath) {
                $stateChanges[$legacyPath] = $result->payload;
            }
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $result->success ? self::HANDLE_SUCCESS : self::HANDLE_ERROR,
            stateChanges: $stateChanges,
            metadata: array_filter([
                self::STATUS_CODE_META     => $result->metadata['status_code'] ?? null,
                self::ERROR_CODE_META      => $result->errorCode,
                self::IDEMPOTENCY_KEY_META => $callContext->idempotencyKey,
                self::ATTEMPT_META         => $outcome->attempt,
                self::DEDUPLICATED_META    => $outcome->replayed ? true : null,
            ], static fn (mixed $v): bool => null !== $v),
        );
    }

    /**
     * Sends the call, or replays the outcome of an earlier successful execution of the same key.
     *
     * A repeated execution of one step (the queue retried it after the worker died, or the session save lost a
     * race) must not repeat the effect at the remote system, so a stored success is returned without calling the
     * transport. Otherwise the send is counted, and its number travels to the transport as the attempt number
     * (overwriting whatever the flow author put under that option); only a success is recorded, because a failure
     * has to stay retryable. See `adr-20261118-call-outbound-idempotency-strategy`.
     *
     * `$earlier` is the ledger's answer for this execution, looked up once by the caller so that the quota check
     * and the send decide on the same record.
     *
     * With a `$retry` policy a failure it calls retryable is sent again after a backoff, with the same key and the
     * next attempt number, for as long as the policy allows and the send fits the node's window; the request timeout
     * of a retry is cut to what is left of the window. The waits block this worker, which is why the window is short.
     */
    private function dispatch(
        string $transportId,
        CallRequest $prepared,
        CallContext $callContext,
        ?CallResult $earlier,
        ?CallSuccessStatuses $statuses = null,
        ?CallRetryPolicy $retry = null,
    ): CallOutcome {
        if (null !== $earlier) {
            return new CallOutcome($earlier, null, true);
        }

        $startedAt = CarbonImmutable::now();
        $timeout   = (int) ($prepared->options['timeout'] ?? HttpTransport::DEFAULT_TIMEOUT_SECONDS);
        $options   = $prepared->options;
        $sends     = 0;

        while (true) {
            $attempt = $this->ledger->startAttempt($callContext->tenantId, $callContext->idempotencyKey);
            // Named `$request` on purpose: OutboundGateCoverageTest finds the delivery by `->execute($request,`.
            $request = new CallRequest(
                target: $prepared->target,
                parameters: $prepared->parameters,
                options: [...$options, CallAttempt::OPTION => $attempt],
                credential: $prepared->credential,
            );

            $result = $this->transports->get($transportId)->execute($request, $callContext);
            ++$sends;

            // The node's verdict, not the transport's, decides what is recorded: a status the list rejects stays
            // retryable, and one it accepts is a finished call that must not be sent again.
            if ($statuses instanceof CallSuccessStatuses) {
                $result = $statuses->judge($result);
            }

            if ($result->success) {
                $this->ledger->complete($callContext->tenantId, $callContext->idempotencyKey, $result);

                return new CallOutcome($result, $attempt, false, $sends);
            }

            if (! $retry instanceof CallRetryPolicy || $sends >= $retry->maxAttempts || ! $retry->retries($result)) {
                return new CallOutcome($result, $attempt, false, $sends);
            }

            $delayMs = $retry->delayMs($sends, $result);
            $left    = CallRetryPolicy::secondsLeft($startedAt->diffInMilliseconds(CarbonImmutable::now()), $delayMs);

            if (null === $left) {
                return new CallOutcome($result, $attempt, false, $sends, retryStopped: true);
            }

            Sleep::for($delayMs)->milliseconds();

            // A timeout of 0 (none) or below is not a limit the window can keep, so the retry gets what is left.
            $options = [...$options, 'timeout' => $timeout > 0 ? min($timeout, $left) : $left];
        }
    }

    /**
     * Spends one call execution; on a refusal answers with the node's `error` handle and no call is made.
     *
     * The unit is the execution key ({@see self::executionKey()}), the same value the transport gets as
     * {@see CallContext::$idempotencyKey}: new on every pass of a loop, unchanged when the queue retries the step.
     */
    private function refuseWhenVolumeUsedUp(NodeExecutionContext $context, ?CallResult $earlier): ?NodeExecutionResult
    {
        // A replay sends nothing and its unit was spent by the first run: it must not be refused when the
        // period has rolled over and the tenant is at its limit.
        if (null !== $earlier) {
            return null;
        }

        $decision = $this->quota->consume(
            self::LIMIT_KEY,
            UsageUnitKey::make('call:', $this->executionKey($context)),
            CarbonImmutable::now(),
            RefusedWork::CallExecution,
        );

        if ($decision->allowed) {
            return null;
        }

        $message = $decision->message ?? 'Call executions limit reached for this period.';

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: self::HANDLE_ERROR,
            metadata: [
                self::ERROR_TYPE_META => 'limit_reached',
                self::ERROR_META      => $message,
            ],
            // The flow log keeps only this field, so the reason is visible there.
            errorMessage: 'limit_reached: ' . $message,
        );
    }

    private function buildContext(NodeExecutionContext $context): CallContext
    {
        return new CallContext(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            nodeId: $context->nodeId,
            idempotencyKey: $this->executionKey($context),
        );
    }

    /**
     * Identifies one execution of this node: the engine's key (it changes with each persisted step, so every
     * pass of a loop gets its own, and it stays the same when the queue retries the step) plus the node id.
     * The node id is a defensive suffix: for an inbound message the engine key is `update|session`, but only
     * the first node of the run gets it, and the suffix keeps keys apart should that ever change.
     *
     * `{session}:{node}` would repeat on each loop iteration, and a transport or receiver that deduplicates
     * by the key would swallow every call after the first.
     */
    private function executionKey(NodeExecutionContext $context): string
    {
        return $context->idempotencyKey . ':' . $context->nodeId;
    }

    /**
     * @param  array<string, mixed>  $stateChanges
     */
    private function writeVariable(
        ?Variable $variable,
        mixed $value,
        NodeExecutionContext $context,
        array &$stateChanges,
    ): void {
        if (! $variable instanceof Variable) {
            return;
        }

        $path = $this->variableResolver->resolveTargetPath($variable);

        if (VariableStorage::Contact === $variable->storage) {
            $writer = $context->contactWriter;

            if (! $writer instanceof ContactWriterInterface) {
                throw new InvalidNodeConfigException(
                    'call: ContactWriter is unavailable for contact-scoped variable.',
                );
            }

            $writer->write($path, $value);

            return;
        }

        $stateChanges[$path] = $value;
    }

    /**
     * @param  mixed  $raw  The `save_to_variable` / mapping `to` shape (array) or null.
     */
    private function resolveVariable(mixed $raw): ?Variable
    {
        return is_array($raw) ? Variable::tryFromArray($raw) : null;
    }
}
