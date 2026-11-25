<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Domains\Messaging\Exceptions\ChannelNotFoundException;
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
 * Delivers one prepared outbound message on the low-priority broadcast queue.
 *
 * @deprecated Nothing dispatches it: it carried the per-contact messages of a `notify` node's retired contacts
 *             mode, whose fan-out job is deprecated too, and Broadcasting sends through its own
 *             `SendBroadcastRecipientJob`. It still delivers, so the already prepared messages an older release
 *             queued at the deploy are not cut short. Remove it once a Core release has shipped with it.
 *
 * Delivery runs inside the owning tenant's context, resolved from the envelope's tenantId.
 */
final class BroadcastSendJob implements ShouldQueue, TenantAccessGatedJob
{
    use Queueable;

    /**
     * @param  OutboundMessage  $message  Fully prepared outbound message envelope. It carries no secret by
     *                                    contract; the channel is loaded by the sender when the job runs.
     */
    public function __construct(public readonly OutboundMessage $message)
    {
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
            try {
                $result = $sender->send($this->message);
            } catch (ChannelNotFoundException) {
                // Deleted since the job was queued: there is no one left to send through, and a retry
                // would find nothing either.
                return;
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
}
