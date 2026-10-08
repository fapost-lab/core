<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Jobs;

use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Models\BroadcastRecipient;
use App\Domains\Channels\Models\Channel;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\DefersWhenDroppedWhileStopped;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Settings\TenantSettings;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Delivers one broadcast message to one recipient and records the outcome.
 * Idempotent on the recipient's Pending status, so a retry never double-sends or
 * double-counts. Localizes the body per the recipient's language chain, sends
 * through the shared MessageSender (which also captures the outbound transcript
 * with origin=broadcast), and advances the broadcast to Completed once the last
 * recipient is processed.
 */
final class SendBroadcastRecipientJob implements DefersWhenDroppedWhileStopped, ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $recipientId,
        public readonly ?string $broadcastId = null,
    ) {
        $this->onQueue('messaging.broadcast');
    }

    public function accessModeTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * Dropped, not postponed, while the tenant is stopped: a big broadcast has a job per recipient,
     * and a delayed copy of each would swamp the shared queue. The recipient row stays Pending, and
     * {@see self::deferUntilActive()} sees to one {@see RunBroadcastJob} that fans the Pending rows out again.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Drop)];
    }

    /**
     * Queues one delayed re-run of the broadcast for all the recipient jobs dropped in this stretch.
     * A job queued before `broadcastId` existed names no broadcast and is simply lost.
     */
    public function deferUntilActive(): void
    {
        if (null === $this->broadcastId) {
            return;
        }

        // Held a little longer than the postponement, so the flag and the re-run copy overlap.
        if (! Cache::add("broadcast_resume:{$this->tenantId}:{$this->broadcastId}", true, RespectsTenantAccessMode::POSTPONE_SECONDS * 2)) {
            return;
        }

        RunBroadcastJob::dispatch($this->tenantId, $this->broadcastId)->delay(RespectsTenantAccessMode::POSTPONE_SECONDS);
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        MessageSenderInterface $sender,
        ContentTranslatorInterface $translator,
    ): void {
        $tenant = $tenants->getById($this->tenantId);

        $switcher->runForTenant($tenant, function () use ($sender, $translator): void {
            /** @var BroadcastRecipient|null $recipient */
            $recipient = BroadcastRecipient::query()
                ->with(['broadcast.assistant', 'contact'])
                ->find($this->recipientId);

            // Idempotency: only a still-pending recipient is actionable.
            if (null === $recipient || RecipientStatus::Pending !== $recipient->status) {
                return;
            }

            $broadcast = $recipient->broadcast;
            $contact   = $recipient->contact;
            $channel   = Channel::query()->find($recipient->channel_id);

            if (BroadcastStatus::Cancelled === $broadcast->status) {
                $this->finish($recipient, RecipientStatus::Skipped, 'skipped_count');

                return;
            }

            if (null === $contact || null === $channel) {
                $this->finish($recipient, RecipientStatus::Failed, 'failed_count', 'contact or channel missing');

                return;
            }

            $language = $this->resolveLanguage(
                (string) $contact->language,
                (string) $broadcast->assistant->default_language,
                app(TenantSettings::class)->fallback_language,
            );

            $text = $translator->resolveField($broadcast->message, $language);

            if ('' === mb_trim($text)) {
                $this->finish($recipient, RecipientStatus::Skipped, 'skipped_count');

                return;
            }

            try {
                $result = $sender->send($this->buildMessage($broadcast, $recipient, $channel, $contact, $text));
            } catch (Throwable $exception) {
                $this->finish($recipient, RecipientStatus::Failed, 'failed_count', $exception->getMessage());

                return;
            }

            if ($result->sent || $result->duplicate) {
                $this->finish($recipient, RecipientStatus::Sent, 'sent_count', providerMessageId: $result->providerMessageId);

                return;
            }

            $this->finish($recipient, RecipientStatus::Failed, 'failed_count', $result->error ?? 'delivery failed');
        });
    }

    private function buildMessage(
        Broadcast $broadcast,
        BroadcastRecipient $recipient,
        Channel $channel,
        \App\Domains\Contact\Models\Contact $contact,
        string $text,
    ): OutboundMessage {
        return new OutboundMessage(
            idempotencyKey: "broadcast:{$broadcast->getKey()}:{$recipient->contact_id}",
            tenantId: $this->tenantId,
            channelId: (string) $channel->getKey(),
            channelType: $channel->type->value,
            transportToken: $channel->token,
            chatId: (string) $contact->external_id,
            payload: new MessagePayload(type: 'text', text: $text),
            metadata: [
                'parse_mode'   => 'HTML',
                'contact_id'   => (string) $recipient->contact_id,
                'assistant_id' => (string) $broadcast->assistant_id,
                'origin'       => MessageOrigin::Broadcast->value,
                'origin_ref'   => ['broadcast_id' => (string) $broadcast->getKey()],
            ],
        );
    }

    /**
     * Record the recipient outcome, bump the matching broadcast counter, and
     * complete the broadcast once every recipient has been processed.
     */
    private function finish(
        BroadcastRecipient $recipient,
        RecipientStatus $status,
        string $counter,
        ?string $error = null,
        ?string $providerMessageId = null,
    ): void {
        $recipient->update([
            'status'              => $status->value,
            'error'               => $error,
            'provider_message_id' => $providerMessageId,
            'sent_at'             => RecipientStatus::Sent === $status ? Carbon::now() : null,
        ]);

        Broadcast::query()->whereKey($recipient->broadcast_id)->increment($counter);

        $this->completeIfDone((string) $recipient->broadcast_id);
    }

    private function completeIfDone(string $broadcastId): void
    {
        /** @var Broadcast|null $broadcast */
        $broadcast = Broadcast::query()->find($broadcastId);

        if (null === $broadcast) {
            return;
        }

        $processed = $broadcast->sent_count + $broadcast->failed_count + $broadcast->skipped_count;

        if ($processed < $broadcast->total_recipients) {
            return;
        }

        // Conditional transition — only the last finisher flips Running → Completed.
        Broadcast::query()
            ->whereKey($broadcastId)
            ->where('status', BroadcastStatus::Running->value)
            ->update([
                'status'       => BroadcastStatus::Completed->value,
                'completed_at' => Carbon::now(),
            ]);
    }

    private function resolveLanguage(string $contactLanguage, string $assistantLanguage, string $fallback): string
    {
        foreach ([$contactLanguage, $assistantLanguage, $fallback] as $candidate) {
            if ('' !== mb_trim($candidate)) {
                return $candidate;
            }
        }

        return 'en';
    }
}
