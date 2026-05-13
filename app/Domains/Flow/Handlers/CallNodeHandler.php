<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\HttpClientInterface;
use App\Domains\Flow\Exceptions\HttpTransportException;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Field;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;

final class CallNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "call";

    private const string ERROR_META           = "error";
    private const string ERROR_TYPE_META      = "error_type";
    private const string IDEMPOTENCY_KEY_META = "idempotency_key";
    private const string STATUS_CODE_META     = "status_code";
    private const string TRANSPORT_ERROR_TYPE = "transport";

    public function __construct(
        private readonly HttpClientInterface $http,
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
            ->section(
                Section::make('connection', 'Connection')
                    ->icon('globe-alt')
                    ->fields([
                        Field::string('url')
                            ->label('URL')
                            ->required()
                            ->placeholder('https://example.com/webhook'),
                        Field::number('timeout')
                            ->label('Timeout (seconds)')
                            ->default(10)
                            ->min(1)
                            ->max(300),
                    ]),
            )
            ->section(
                Section::make('response', 'Response handling')
                    ->icon('arrow-down-tray')
                    ->fields([
                        Field::statePicker('save_response_to')
                            ->label('Save response to')
                            ->placeholder('flow.webhook_response'),
                    ]),
            )
            ->section(
                Section::make('advanced', 'Advanced')
                    ->icon('cog-6-tooth')
                    ->collapsed()
                    ->fields([
                        Field::keyValue('headers')
                            ->label('Custom headers')
                            ->keyLabel('Header')
                            ->valueLabel('Value')
                            ->placeholder(['Authorization' => 'Bearer ...'])
                            ->help(
                                'Sent alongside the request. Reserved X-* headers set by the engine cannot be overridden.'
                            ),
                        Field::array('include_state')
                            ->label('Include state')
                            ->help('State paths whose values are forwarded in the request payload.'),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $url    = $config['url'] ?? null;

        if ( ! is_string($url) || '' === $url) {
            throw new InvalidNodeConfigException('call: missing url');
        }

        $idempotencyKey = "{$context->sessionId}:{$context->nodeId}";
        $payload        = $this->buildPayload($config, $state, $context);

        // Engine-set headers must always win — they're how the receiving
        // service correlates retries and traces the originating session.
        // Author-provided custom headers fill the rest of the request.
        $headers    = [];
        $rawHeaders = $config['headers'] ?? null;
        if (is_array($rawHeaders)) {
            foreach ($rawHeaders as $headerKey => $headerValue) {
                if ( ! is_string($headerKey) || '' === $headerKey) {
                    continue;
                }
                if (str_starts_with($headerKey, 'X-Idempotency-Key') || str_starts_with($headerKey, 'X-FAPost-')) {
                    continue;
                }
                $headers[$headerKey] = (string)$headerValue;
            }
        }
        $headers['X-Idempotency-Key'] = $idempotencyKey;
        $headers['X-FAPost-Session']  = $context->sessionId;

        try {
            $response = $this->http->post(
                url: $url,
                payload: $payload,
                headers: $headers,
                timeout: (int)($config['timeout'] ?? 10),
            );
        } catch (HttpTransportException $exception) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Failed,
                metadata: [
                    self::ERROR_META           => $exception->getMessage(),
                    self::ERROR_TYPE_META      => self::TRANSPORT_ERROR_TYPE,
                    self::IDEMPOTENCY_KEY_META => $idempotencyKey,
                ],
            );
        }

        $stateChanges   = [];
        $saveResponseTo = $config['save_response_to'] ?? null;
        if (is_string($saveResponseTo) && '' !== $saveResponseTo) {
            $stateChanges[$saveResponseTo] = $response->json();
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $response->successful() ? 'success' : 'error',
            stateChanges: $stateChanges,
            metadata: [
                self::STATUS_CODE_META     => $response->status(),
                self::IDEMPOTENCY_KEY_META => $idempotencyKey,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>
     */
    private function buildPayload(array $config, array $state, NodeExecutionContext $context): array
    {
        $payload = [
            'session_id' => $context->sessionId,
            'contact_id' => $context->contactId,
        ];

        $includeState = is_array($config['include_state'] ?? null) ? $config['include_state'] : [];
        foreach ($includeState as $path) {
            if ( ! is_string($path)) {
                continue;
            }

            $payload['state'][$path] = data_get($state, $path);
        }

        return $payload;
    }
}
