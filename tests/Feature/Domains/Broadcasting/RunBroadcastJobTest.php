<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Jobs\SendBroadcastRecipientJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Models\ContactTag;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

final class RunBroadcastJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_materializes_deliverable_recipients_and_fans_out_send_jobs(): void
    {
        Bus::fake();

        $channel = $this->channel();
        $this->bind($this->contact(), $channel);
        $this->bind($this->contact(), $channel);
        $this->contact(); // unbound → not deliverable

        $broadcast = $this->runningBroadcast((string) $channel->assistant_id);

        $this->runJob($broadcast);

        $broadcast->refresh();
        $this->assertSame(2, $broadcast->total_recipients);
        $this->assertSame(2, DB::table('broadcast_recipients')->where('broadcast_id', $broadcast->getKey())->count());
        Bus::assertDispatchedTimes(SendBroadcastRecipientJob::class, 2);
    }

    public function test_empty_audience_completes_immediately(): void
    {
        Bus::fake();

        $channel   = $this->channel();
        $broadcast = $this->runningBroadcast((string) $channel->assistant_id, target: 'tags', tags: ['nobody']);

        $this->runJob($broadcast);

        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Completed, $broadcast->status);
        $this->assertSame(0, $broadcast->total_recipients);
        $this->assertNotNull($broadcast->completed_at);
        Bus::assertNotDispatched(SendBroadcastRecipientJob::class);
    }

    public function test_segment_target_only_reaches_matching_contacts(): void
    {
        Bus::fake();

        $channel = $this->channel();

        $vip = $this->contact();
        ContactTag::query()->create(['contact_id' => $vip->getKey(), 'tag' => 'vip', 'tagged_by' => null, 'tagged_at' => now()]);
        $this->bind($vip, $channel);

        $this->bind($this->contact(), $channel); // untagged → excluded by the segment

        $segment = ContactSegment::query()->create([
            'tenant_id' => self::TENANT_ID,
            'name'      => 'VIP',
            'rules'     => ['match' => 'all', 'conditions' => [['type' => 'tag', 'operator' => 'has', 'value' => 'vip']]],
        ]);

        $broadcast = Broadcast::query()->create([
            'tenant_id'         => self::TENANT_ID,
            'assistant_id'      => (string) $channel->assistant_id,
            'name'              => 'Promo',
            'message'           => 'Hello!',
            'target_type'       => 'segment',
            'target_segment_id' => $segment->getKey(),
            'status'            => BroadcastStatus::Running->value,
        ]);

        $this->runJob($broadcast);

        $this->assertSame(1, $broadcast->fresh()->total_recipients);
        Bus::assertDispatchedTimes(SendBroadcastRecipientJob::class, 1);
    }

    private function runJob(Broadcast $broadcast): void
    {
        $job = new RunBroadcastJob(self::TENANT_ID, (string) $broadcast->getKey());
        app()->call([$job, 'handle']);
    }

    private function runningBroadcast(string $assistantId, string $target = 'all', array $tags = []): Broadcast
    {
        return Broadcast::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistantId,
            'name'         => 'Promo',
            'message'      => 'Hello!',
            'target_type'  => $target,
            'target_tags'  => [] === $tags ? null : $tags,
            'status'       => BroadcastStatus::Running->value,
        ]);
    }

    private function channel(): Channel
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);

        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
    }

    private function contact(): Contact
    {
        return Contact::factory()->forTenant(self::TENANT_ID)->create();
    }

    private function bind(Contact $contact, Channel $channel): void
    {
        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);
    }
}
