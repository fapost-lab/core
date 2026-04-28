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

final class WebhookNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "webhook";

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
        return [
            'url' => [
                'type'        => 'string',
                'label'       => 'URL',
                'required'    => true,
                'placeholder' => 'https://example.com/webhook',
            ],
            'timeout' => [
                'type'     => 'number',
                'label'    => 'Timeout (seconds)',
                'required' => false,
                'default'  => 10,
            ],
            'save_response_to' => [
                'type'        => 'state-picker',
                'label'       => 'Save response to',
                'required'    => false,
                'placeholder' => 'flow.webhook_response',
            ],
            'include_state' => [
                'type'     => 'array',
                'label'    => 'Include state',
                'required' => false,
            ],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $url    = $config['url'] ?? null;

        if ( ! is_string($url) || '' === $url) {
            throw new InvalidNodeConfigException('webhook: missing url');
        }

        $idempotencyKey = "{$context->sessionId}:{$context->nodeId}";
        $payload        = $this->buildPayload($config, $state, $context);

        try {
            $response = $this->http->post(
                url: $url,
                payload: $payload,
                headers: [
                    'X-Idempotency-Key' => $idempotencyKey,
                    'X-FAPost-Session'  => $context->sessionId,
                ],
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
