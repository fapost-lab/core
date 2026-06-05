<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Call\CallContext;
use FAPost\Foundation\Flow\Call\CallRequest;
use FAPost\Foundation\Flow\Call\CallResult;
use FAPost\Foundation\Flow\Contracts\ContactWriterInterface;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\ObjectArrayField;
use FAPost\Support\Builder\Schema\Fields\ObjectField;
use FAPost\Support\Builder\Schema\Fields\SelectField;
use FAPost\Support\Builder\Schema\Fields\TextField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;
use JsonException;

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
 * Legacy nodes (no `transport`/`target`) keep their original behavior through
 * {@see executeLegacy()} — POST `url` with `include_state`, save body to
 * `save_response_to` / `save_to_variable`.
 */
final class CallNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'call';

    private const string ERROR_META           = 'error';
    private const string ERROR_TYPE_META      = 'error_type';
    private const string ERROR_CODE_META      = 'error_code';
    private const string IDEMPOTENCY_KEY_META = 'idempotency_key';
    private const string STATUS_CODE_META     = 'status_code';

    private const string HANDLE_SUCCESS = 'success';
    private const string HANDLE_ERROR   = 'error';

    private const string DEFAULT_TRANSPORT = 'http';

    public function __construct(
        private readonly CallTransportRegistry $transports,
        private readonly TemplateRenderer $templates,
        private readonly VariableResolverInterface $variableResolver,
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
                    ]),
            )
            ->toArray();
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

        $callContext = $this->buildContext($context);
        $request     = new CallRequest(target: $target, parameters: $parameters, options: $options);
        $result      = $this->transports->get($transportId)->execute($request, $callContext);

        $stateChanges = [];
        $this->applyResponse($config, $result, $context, $stateChanges);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $result->success ? self::HANDLE_SUCCESS : self::HANDLE_ERROR,
            stateChanges: $stateChanges,
            metadata: array_filter([
                self::STATUS_CODE_META     => $result->metadata['status_code'] ?? null,
                self::ERROR_CODE_META      => $result->errorCode,
                self::IDEMPOTENCY_KEY_META => $callContext->idempotencyKey,
            ], static fn (mixed $v): bool => null !== $v),
        );
    }

    /**
     * Write the whole-response variable and apply each result_mapping entry.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $stateChanges
     */
    private function applyResponse(
        array $config,
        CallResult $result,
        NodeExecutionContext $context,
        array &$stateChanges,
    ): void {
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

        $mappings = is_array($config['result_mapping'] ?? null) ? $config['result_mapping'] : [];
        foreach ($mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $from = is_string($mapping['from'] ?? null) && '' !== $mapping['from'] ? $mapping['from'] : null;
            $to   = $this->resolveVariable($mapping['to'] ?? null);

            if (null === $from || ! $to instanceof Variable) {
                continue;
            }

            // Missing path resolves to null (written as-is) rather than failing
            // the node — the author opted into the mapping, an absent field is
            // expected to surface as an empty variable.
            $this->writeVariable($to, data_get($bag, $from), $context, $stateChanges);
        }
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
            if (str_starts_with($headerKey, 'X-Idempotency-Key') || str_starts_with($headerKey, 'X-FAPost-')) {
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
        $result      = $this->transports->get(self::DEFAULT_TRANSPORT)->execute($request, $callContext);

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
            ], static fn (mixed $v): bool => null !== $v),
        );
    }

    private function buildContext(NodeExecutionContext $context): CallContext
    {
        return new CallContext(
            tenantId: $context->tenantId,
            contactId: $context->contactId,
            sessionId: $context->sessionId,
            nodeId: $context->nodeId,
            idempotencyKey: "{$context->sessionId}:{$context->nodeId}",
        );
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
