<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Jobs;

use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastRecipientResolver;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Settings\TenantSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Fan-out orchestrator for a broadcast run. Resolves the audience, materializes
 * one {@see \App\Domains\Broadcasting\Models\BroadcastRecipient} per contact, then
 * dispatches a {@see SendBroadcastRecipientJob} per recipient — rate-shaped into
 * per-second chunks so a large audience doesn't burst the provider.
 *
 * Backpressure: when enabled and the broadcast queue is already deep, the run is
 * re-scheduled (before any recipient rows are created, so it's cheap to retry)
 * instead of piling more work on.
 */
final class RunBroadcastJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    private const int BACKPRESSURE_QUEUE_THRESHOLD = 1000;

    private const int BACKPRESSURE_RETRY_SECONDS = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $broadcastId,
    ) {
        $this->onQueue('messaging.broadcast');
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        BroadcastRecipientResolver $resolver,
    ): void {
        $tenant = $tenants->getById($this->tenantId);

        $switcher->runForTenant($tenant, function () use ($resolver): void {
            $broadcast = Broadcast::query()->find($this->broadcastId);

            if (null === $broadcast || BroadcastStatus::Running !== $broadcast->status) {
                return;
            }

            $settings = app(TenantSettings::class);

            if ($settings->broadcast_backpressure
                && Queue::size('messaging.broadcast') > self::BACKPRESSURE_QUEUE_THRESHOLD) {
                // Defer the whole run — nothing has been materialized yet.
                self::dispatch($this->tenantId, $this->broadcastId)
                    ->delay(now()->addSeconds(self::BACKPRESSURE_RETRY_SECONDS));

                return;
            }

            $recipients = $resolver->resolve($broadcast);

            if ($recipients->isEmpty()) {
                $broadcast->update([
                    'status'           => BroadcastStatus::Completed->value,
                    'total_recipients' => 0,
                    'completed_at'     => Carbon::now(),
                ]);

                return;
            }

            $this->materializeRecipients($broadcast, $recipients);
            $this->fanOut($broadcast, max(1, $settings->broadcast_chunk_size));
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ChannelContact>  $recipients
     */
    private function materializeRecipients(Broadcast $broadcast, \Illuminate\Support\Collection $recipients): void
    {
        $now  = Carbon::now();
        $rows = [];

        foreach ($recipients as $channelContact) {
            $rows[] = [
                'id'           => (string) Str::ulid()->toRfc4122(),
                'tenant_id'    => $this->tenantId,
                'broadcast_id' => $this->broadcastId,
                'contact_id'   => (string) $channelContact->contact_id,
                'channel_id'   => (string) $channelContact->channel_id,
                'status'       => RecipientStatus::Pending->value,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        // insertOrIgnore + the (broadcast_id, contact_id) unique index makes the
        // materialization idempotent if the run is ever retried.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('broadcast_recipients')->insertOrIgnore($chunk);
        }

        $broadcast->update(['total_recipients' => count($rows)]);
    }

    private function fanOut(Broadcast $broadcast, int $chunkSize): void
    {
        $index = 0;

        DB::table('broadcast_recipients')
            ->where('broadcast_id', $broadcast->getKey())
            ->where('status', RecipientStatus::Pending->value)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (string $recipientId) use (&$index, $chunkSize): void {
                SendBroadcastRecipientJob::dispatch($this->tenantId, $recipientId)
                    ->delay(now()->addSeconds(intdiv($index, $chunkSize)));
                $index++;
            });
    }
}
