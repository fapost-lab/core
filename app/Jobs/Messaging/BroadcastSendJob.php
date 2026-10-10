<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Queue job responsible for low-priority broadcast outbound deliveries.
 *
 * Delivery runs inside the owning tenant's context (resolved from the envelope's
 * tenantId) so future per-recipient bookkeeping — broadcast_recipients status,
 * delivery logs — lands in the correct schema without reworking the job contract.
 */
final class BroadcastSendJob implements ShouldQueue, TenantAccessGatedJob
{
    use Queueable;

    /**
     * The envelope as it is queued: without the transport token, which the job loads from the channel
     * when it runs. A token in the envelope would be serialized into `jobs` and `failed_jobs`.
     */
    public readonly OutboundMessage $message;

    /**
     * @param  OutboundMessage  $message  Fully prepared outbound message envelope; its transport token is dropped.
     */
    public function __construct(OutboundMessage $message)
    {
        $this->message = $this->withoutToken($message);

        $this->onQueue('messaging.broadcast');
    }

    public function accessModeTenantId(): string
    {
        return $this->message->tenantId;
    }

    /**
     * Dropped while the tenant is stopped. These are the per-contact messages of a flow's contact
     * notification, whose parent job is dropped too and which keep no record to fan out from again;
     * a delayed copy per contact would only fill the shared queue.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Drop)];
    }

    /**
     * Deliver the message and bubble non-duplicate failures for queue retries; an exhausted outbound
     * volume is the exception, which is final and ends the job quietly.
     */
    public function handle(
        MessageSenderInterface $sender,
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
    ): void {
        $tenant = $tenants->getById($this->message->tenantId);

        $switcher->runForTenant($tenant, function () use ($sender): void {
            $channel = Channel::query()->find($this->message->channelId);

            // Deleted since the job was queued: there is no one left to send through.
            if (null === $channel) {
                return;
            }

            try {
                $result = $sender->send($this->withToken($this->message, $channel->token));
            } catch (VolumeLimitReachedException $exception) {
                // Final for the period: a retry would only ask the operator again. These notifications
                // keep no per-recipient record, so the refusal is logged and the message is dropped.
                Log::info('Broadcast message dropped: outbound message limit reached.', [
                    'tenant_id' => $this->message->tenantId,
                    'key'       => $exception->key,
                    'limit'     => $exception->limit,
                    'used'      => $exception->used,
                ]);

                return;
            }

            if (! $result->sent && ! $result->duplicate) {
                throw new RuntimeException($result->error ?? 'Broadcast message delivery failed.');
            }
        });
    }

    private function withoutToken(OutboundMessage $message): OutboundMessage
    {
        return $this->withToken($message, null);
    }

    private function withToken(OutboundMessage $message, ?string $token): OutboundMessage
    {
        return new OutboundMessage(
            idempotencyKey: $message->idempotencyKey,
            tenantId: $message->tenantId,
            channelId: $message->channelId,
            channelType: $message->channelType,
            transportToken: $token,
            chatId: $message->chatId,
            payload: $message->payload,
            metadata: $message->metadata,
        );
    }
}
