<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use App\Domains\Credentials\CredentialTypeRegistry;
use App\Domains\Credentials\SecretRedactor;
use App\Domains\Flow\Call\Transports\HttpTransport;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\Support\UsageUnitKey;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialNotFoundException;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
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
 * A `credential_id` in the config is resolved as the node does, with the same error codes (`credential_unsupported`,
 * `credential_not_found`, `credential_unavailable`), and a refused credential costs no quota. The transport masks
 * the credential's secrets in its result; an exception message is masked here with the credential's secret fields.
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
        private CredentialStoreInterface $credentials,
        private CredentialTypeRegistry $credentialTypes,
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

        $credential   = null;
        $credentialId = $config['credential_id'] ?? null;

        if (is_string($credentialId) && '' !== $credentialId) {
            $credential = $this->resolveCredential($credentialId, $transportId);

            if (is_string($credential)) {
                return $this->errorResult($credential, 0);
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
            credential: $credential,
        );

        $start = microtime(true);

        try {
            $result = $this->transports->get($transportId)->execute($request, $callContext);
        } catch (Throwable $exception) {
            return [
                'success'     => false,
                'status_code' => null,
                'headers'     => [],
                'body'        => $this->maskedMessage($exception, $credential),
                'error_code'  => 'test_exception',
                'duration_ms' => $this->elapsedMs($start),
            ];
        }

        // The same verdict the node gives, so the builder shows what the flow will do.
        $statuses = CallSuccessStatuses::fromConfig($config['success_statuses'] ?? null);

        if ($statuses instanceof CallSuccessStatuses) {
            $result = $statuses->judge($result);
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
     * The credential, or the error code that says why it cannot be used.
     */
    private function resolveCredential(string $id, string $transportId): CredentialSecret|string
    {
        if (HttpTransport::ID !== $transportId) {
            return 'credential_unsupported';
        }

        try {
            return $this->credentials->secret($id);
        } catch (CredentialNotFoundException) {
            return 'credential_not_found';
        } catch (CredentialUnavailableException) {
            return 'credential_unavailable';
        }
    }

    /**
     * The exception message with the credential's secret fields replaced by `***`.
     */
    private function maskedMessage(Throwable $exception, ?CredentialSecret $credential): string
    {
        $message = $exception->getMessage();

        if (! $credential instanceof CredentialSecret || ! $this->credentialTypes->has($credential->type)) {
            return $message;
        }

        $secrets = [];

        foreach ($this->credentialTypes->get($credential->type)->fields() as $field) {
            $value = $field->secret ? $credential->value($field->key) : null;

            if (null !== $value) {
                $secrets[] = $value;
            }
        }

        $masked = SecretRedactor::redact($message, $secrets);

        return is_string($masked) ? $masked : $message;
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
