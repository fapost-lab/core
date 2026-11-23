<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Auth\AuthCodeStore;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderRegistryInterface;
use Fapost\Foundation\Auth\DTO\OneTimeCodeMessage;
use Fapost\Foundation\Auth\Enums\OneTimeCodeChannel;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Delivers one one-time code an `auth_request@2` node issued, through the sender registered for its channel.
 *
 * The payload is encrypted ({@see ShouldBeEncrypted}): the queue, Horizon and the failed-jobs table never hold the
 * code in clear text. One attempt only, behind a cache guard keyed by the send: a retried or duplicated job never
 * delivers twice, and a delivery that did not happen is recorded in {@see AuthCodeStore} so the node routes the
 * contact to `failed` instead of waiting for a code that never left. Nothing about the code or the destination is
 * logged. Runs on `messaging.system`, inside the tenant whose flow asked for the code; a stopped tenant's code is
 * dropped — it would have expired before the tenant came back.
 */
final class SendOneTimeCodeJob implements ShouldQueue, ShouldBeEncrypted, TenantAccessGatedJob
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $sessionId,
        public readonly string $nodeId,
        public readonly string $sendToken,
        public readonly string $channel,
        public readonly string $destination,
        #[SensitiveParameter]
        public readonly string $code,
        public readonly string $locale,
        public readonly int $expiresInMinutes,
    ) {
        $this->onQueue('messaging.system');
    }

    public function idempotencyKey(): string
    {
        return "auth_code:{$this->tenantId}:{$this->sessionId}:{$this->nodeId}:{$this->sendToken}";
    }

    public function accessModeTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Drop)];
    }

    public function handle(
        Repository $cache,
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        OneTimeCodeSenderRegistryInterface $senders,
        AuthCodeStore $codes,
        LoggerInterface $logger,
    ): void {
        if (! $cache->add('guard:' . $this->idempotencyKey(), true, now()->addDay())) {
            return;
        }

        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($senders, $codes, $logger): void {
            $channel = OneTimeCodeChannel::tryFrom($this->channel);
            $sender  = null !== $channel ? $senders->find($channel) : null;

            if (null === $sender || null === $channel) {
                $this->recordFailure($codes, $logger, 'no_sender');

                return;
            }

            try {
                $delivery = $sender->send(new OneTimeCodeMessage(
                    idempotencyKey: $this->idempotencyKey(),
                    tenantId: $this->tenantId,
                    channel: $channel,
                    destination: $this->destination,
                    code: $this->code,
                    locale: $this->locale,
                    brandName: $this->brandName(),
                    expiresInMinutes: $this->expiresInMinutes,
                ));
            } catch (Throwable $exception) {
                $this->recordFailure($codes, $logger, 'sender_exception: ' . $exception::class);

                return;
            }

            if (! $delivery->sent) {
                $this->recordFailure($codes, $logger, (string) $delivery->error);
            }
        });
    }

    private function brandName(): string
    {
        $assistantId = FlowSession::query()->whereKey($this->sessionId)->value('assistant_id');
        $name        = null !== $assistantId ? Assistant::query()->whereKey($assistantId)->value('name') : null;

        return is_string($name) && '' !== mb_trim($name) ? $name : (string) config('app.name');
    }

    private function recordFailure(AuthCodeStore $codes, LoggerInterface $logger, string $reason): void
    {
        $codes->markDeliveryFailed($this->tenantId, $this->sessionId, $this->nodeId, $this->sendToken);

        $logger->warning('auth_request one-time code was not delivered', [
            'tenant'  => $this->tenantId,
            'session' => $this->sessionId,
            'node'    => $this->nodeId,
            'channel' => $this->channel,
            'reason'  => $reason,
        ]);
    }
}
