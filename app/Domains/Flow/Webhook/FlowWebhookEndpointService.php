<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Flow\Contracts\FlowWebhookEndpointRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowWebhookEndpoint;
use App\Domains\Flow\Services\FlowTriggerSet;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Support\TenantHost;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The builder's side of a webhook trigger's endpoint: making its URL and setting its secret.
 *
 * A trigger is served only for the flow whose draft lists it as a webhook trigger, and an endpoint stays with the flow
 * it was made for: one flow's editor never reaches another flow's URL or secret through a trigger id. The endpoint is
 * made before the trigger is published, so the sender can be set up first; requests are refused until it is live.
 *
 * The secret is shown once, in the answer that generated it; afterwards only whether there is one.
 *
 * Bound scoped: it reads the tenant context.
 */
final readonly class FlowWebhookEndpointService
{
    public const int MIN_SECRET_LENGTH = 16;

    public const int MAX_SECRET_LENGTH = 512;

    /**
     * @param  ConnectionResolverInterface  $db  asked for its connection at the moment it writes, never kept: a tenant
     *                                         switch moves the default connection the models write through, and a
     *                                         transaction on a connection taken when this is built — before the
     *                                         switch, in a queue job — would not cover them
     */
    public function __construct(
        private FlowWebhookEndpointRepositoryInterface $endpoints,
        private FlowTriggerSet $triggerSet,
        private TenantContextInterface $tenantContext,
        private AuditTrailInterface $audit,
        private ConnectionResolverInterface $db,
    ) {
    }

    /** 32 random bytes in base64url: the path segment of the URL, 43 characters. */
    public static function newToken(): string
    {
        return mb_rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * The endpoint of the draft's webhook trigger, made on first use.
     *
     * @throws WebhookEndpointUnavailableException when the draft has no webhook trigger with that id, or the id is
     *                                            another flow's
     */
    public function ensure(FlowDraft $draft, string $triggerId): FlowWebhookEndpoint
    {
        $triggerId = mb_strtolower($triggerId);
        $this->assertWebhookTriggerOfDraft($draft, $triggerId);

        $endpoint = $this->endpoints->findByTriggerId($triggerId);

        if (null !== $endpoint) {
            if ($endpoint->flow_id !== $draft->flow_id) {
                throw new WebhookEndpointUnavailableException('The trigger belongs to another flow.');
            }

            return $endpoint;
        }

        $endpoint = new FlowWebhookEndpoint();
        $endpoint->fill([
            'tenant_id'  => (string) $this->tenantContext->get()->getId(),
            'flow_id'    => $draft->flow_id,
            'trigger_id' => $triggerId,
            'token'      => self::newToken(),
        ]);

        try {
            $this->db->connection()->transaction(function () use ($endpoint, $draft): void {
                $this->endpoints->save($endpoint);

                $this->audit->record(new AuditRecord(
                    action: AuditAction::FlowWebhookUrlCreated,
                    subject: $draft,
                    subjectLabel: (string) $draft->name,
                    assistantId: $draft->assistant_id,
                ));
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two clicks at once: the other request made it; use that one.
            $made = $this->endpoints->findByTriggerId($triggerId);

            if (null === $made || $made->flow_id !== $draft->flow_id) {
                throw new WebhookEndpointUnavailableException('The trigger belongs to another flow.', 0, $exception);
            }

            return $made;
        }

        return $endpoint;
    }

    /**
     * Replaces the secret with a new random one and returns it: the only time it is shown.
     *
     * @throws WebhookEndpointUnavailableException
     */
    public function generateSecret(FlowDraft $draft, string $triggerId): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->store($draft, $triggerId, $secret);

        return $secret;
    }

    /**
     * Replaces the secret with one the sending system issued (a provider that generates its own signing secret).
     *
     * @throws WebhookEndpointUnavailableException
     * @throws InvalidArgumentException when the secret is too short, too long or has spaces or control characters
     */
    public function setSecret(FlowDraft $draft, string $triggerId, string $secret): void
    {
        $length = mb_strlen($secret);

        if ($length < self::MIN_SECRET_LENGTH || $length > self::MAX_SECRET_LENGTH || 1 === preg_match('/[\s\x00-\x1F\x7F]/u', $secret) || ! mb_check_encoding($secret, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf('A secret is %d to %d characters, without spaces.', self::MIN_SECRET_LENGTH, self::MAX_SECRET_LENGTH));
        }

        $this->store($draft, $triggerId, $secret);
    }

    /**
     * What the builder shows of the flow's endpoints, keyed by trigger id: the URL and whether a secret is set —
     * never the secret.
     *
     * @return array<string, array{url: string, has_secret: bool, secret_rotated_at: string|null}>
     */
    public function describe(string $flowId): array
    {
        $described = [];

        foreach ($this->endpoints->forFlow($flowId) as $triggerId => $endpoint) {
            $described[$triggerId] = $this->present($endpoint);
        }

        return $described;
    }

    /**
     * @return array{url: string, has_secret: bool, secret_rotated_at: string|null}
     */
    public function present(FlowWebhookEndpoint $endpoint): array
    {
        return [
            'url'               => TenantHost::urlFor($this->tenantContext->get(), 'hooks/flow/' . $endpoint->token),
            'has_secret'        => $endpoint->hasSecret(),
            'secret_rotated_at' => $endpoint->secret_rotated_at?->toIso8601String(),
        ];
    }

    /**
     * The new secret and its audit entry commit together; the entry names the flow and never carries the secret.
     */
    private function store(FlowDraft $draft, string $triggerId, string $secret): void
    {
        $endpoint = $this->ensure($draft, $triggerId);

        $this->db->connection()->transaction(function () use ($endpoint, $secret, $draft): void {
            $endpoint->secret            = $secret;
            $endpoint->secret_rotated_at = CarbonImmutable::now();
            $this->endpoints->save($endpoint);

            $this->audit->record(new AuditRecord(
                action: AuditAction::FlowWebhookSecretRotated,
                subject: $draft,
                subjectLabel: (string) $draft->name,
                assistantId: $draft->assistant_id,
            ));
        });
    }

    private function assertWebhookTriggerOfDraft(FlowDraft $draft, string $triggerId): void
    {
        foreach ($this->triggerSet->ofDraft($draft) as $trigger) {
            if ($trigger['id'] === $triggerId && FlowTriggerType::Webhook->value === $trigger['type']) {
                return;
            }
        }

        throw new WebhookEndpointUnavailableException('The flow has no webhook trigger with this id.');
    }
}
