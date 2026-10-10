<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\Support\UsageUnitKey;
use Carbon\CarbonImmutable;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executes a `call` node configuration ad-hoc from the builder so the author
 * can inspect the live response and learn its shape for result_mapping.
 *
 * Runs the SAME transport layer as runtime, so what the author sees matches
 * what the flow will receive. There is no session/contact at authoring time —
 * templates are rendered against a caller-supplied `sample` map instead, and a
 * synthetic {@see CallContext} is handed to the transport.
 *
 * Note: this performs a real outgoing request / handler invocation. Mutating
 * calls (POST/PUT/DELETE, side-effecting actions) genuinely take effect — the
 * UI warns before sending non-GET requests.
 */
final readonly class CallTester
{
    public function __construct(
        private CallTransportRegistry $transports,
        private TemplateRenderer $templates,
        private TenantContextInterface $tenantContext,
        private PeriodQuota $quota,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config  transport / target / parameters / transport_options
     * @param  array<string, mixed>  $sample  flat `{path: value}` map for `{{template}}` resolution
     *
     * @return array{
     *     success: bool,
     *     status_code: int|null,
     *     headers: array<string, mixed>,
     *     body: mixed,
     *     error_code: string|null,
     *     duration_ms: int
     * }
     */
    public function run(array $config, array $sample): array
    {
        $transportId = is_string($config['transport'] ?? null) && '' !== $config['transport']
            ? $config['transport']
            : 'http';

        if (! $this->transports->has($transportId)) {
            return $this->errorResult('unknown_transport', 0);
        }

        // Reconstruct nested state from the flat sample paths so the template
        // renderer's data_get resolution behaves exactly as at runtime.
        $state = [];
        foreach ($sample as $path => $value) {
            if (is_string($path) && '' !== $path) {
                data_set($state, $path, $value);
            }
        }

        $tenantId = $this->tenantContext->isResolved() ? $this->tenantContext->get()->getId() : 'test-tenant';

        $clickId = (string) Str::ulid();

        // The builder always runs inside a tenant; without one there is nobody to bill.
        if ($this->tenantContext->isResolved()) {
            // Every click is its own unit: there is no session, and a repeated test is a repeated call.
            $decision = $this->quota->consume(
                CallNodeHandler::LIMIT_KEY,
                UsageUnitKey::make('call_test:', $clickId),
                CarbonImmutable::now(),
                RefusedWork::CallExecution,
            );

            if (! $decision->allowed) {
                return $this->errorResult('limit_reached', 0, $decision->message ?? 'Call executions limit reached for this period.');
            }
        }

        $renderContext = new NodeExecutionContext(
            tenantId: $tenantId,
            contactId: 'test-contact',
            sessionId: 'test-session',
            nodeId: 'test-node',
            idempotencyKey: 'test',
            platform: 'builder',
        );

        $target     = $this->templates->render($config['target'] ?? '', $renderContext, $state);
        $parameters = $this->templates->render(
            is_array($config['parameters'] ?? null) ? $config['parameters'] : [],
            $renderContext,
            $state,
        );
        $options = $this->templates->render(
            is_array($config['transport_options'] ?? null) ? $config['transport_options'] : [],
            $renderContext,
            $state,
        );

        $callContext = new CallContext(
            tenantId: $tenantId,
            contactId: 'test-contact',
            sessionId: 'test-session',
            nodeId: 'test-node',
            idempotencyKey: 'test:builder:' . $clickId,
        );

        $request = new CallRequest(
            target: is_string($target) ? $target : '',
            parameters: is_array($parameters) ? $parameters : [],
            options: is_array($options) ? $options : [],
        );

        $start = microtime(true);

        try {
            $result = $this->transports->get($transportId)->execute($request, $callContext);
        } catch (Throwable $exception) {
            return [
                'success'     => false,
                'status_code' => null,
                'headers'     => [],
                'body'        => $exception->getMessage(),
                'error_code'  => 'test_exception',
                'duration_ms' => $this->elapsedMs($start),
            ];
        }

        return [
            'success'     => $result->success,
            'status_code' => is_int($result->metadata['status_code'] ?? null) ? $result->metadata['status_code'] : null,
            'headers'     => is_array($result->metadata['headers'] ?? null) ? $result->metadata['headers'] : [],
            'body'        => $result->payload,
            'error_code'  => $result->errorCode,
            'duration_ms' => $this->elapsedMs($start),
        ];
    }

    /**
     * @return array{success: bool, status_code: int|null, headers: array<string, mixed>, body: mixed, error_code: string, duration_ms: int}
     */
    private function errorResult(string $code, int $durationMs, ?string $message = null): array
    {
        return [
            'success'     => false,
            'status_code' => null,
            'headers'     => [],
            'body'        => $message,
            'error_code'  => $code,
            'duration_ms' => $durationMs,
        ];
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
