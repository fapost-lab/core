<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastRecipientResolver;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Models\ContactTag;
use Tests\Feature\FeatureTestCase;

/**
 * The reach a person sees (`count()`) is the number the run will resolve (`resolve()->count()`), for every target and
 * for the cases where deliverability narrows the audience: they share one query, and this pins that they stay equal.
 */
final class BroadcastRecipientResolverCountTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);
    }

    public function test_count_equals_resolve_for_every_target(): void
    {
        $channel = $this->channel();

        $vip = $this->contact();
        $this->tag($vip, 'vip');
        $this->bind($vip, $channel);
        $this->bind($this->contact(), $channel);

        $segment = ContactSegment::query()->create([
            'tenant_id' => self::TENANT_ID,
            'name'      => 'VIP',
            'rules'     => ['match' => 'all', 'conditions' => [['type' => 'tag', 'operator' => 'has', 'value' => 'vip']]],
        ]);

        $this->assertSameCount(2, $this->broadcast('all'));
        $this->assertSameCount(1, $this->broadcast('tags', tags: ['vip']));
        $this->assertSameCount(1, $this->broadcast('segment', segmentId: (string) $segment->getKey()));
    }

    public function test_a_tag_nobody_carries_and_a_missing_segment_count_zero(): void
    {
        $this->bind($this->contact(), $this->channel());

        $this->assertSameCount(0, $this->broadcast('tags', tags: ['nobody']));
        $this->assertSameCount(0, $this->broadcast('tags', tags: []));
        $this->assertSameCount(0, $this->broadcast('segment', segmentId: null));
        $this->assertSameCount(0, $this->broadcast('segment', segmentId: '00000000-0000-0000-0000-0000000000aa'));
    }

    public function test_an_inactive_channel_and_another_assistant_are_not_counted(): void
    {
        $this->bind($this->contact(), $this->channel(active: false));

        $other = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->bind($this->contact(), $this->channel($other));

        $this->assertSameCount(0, $this->broadcast('all'));
    }

    public function test_a_contact_in_two_channels_counts_once(): void
    {
        $contact = $this->contact();
        $this->bind($contact, $this->channel());
        $this->bind($contact, $this->channel());

        $this->assertSameCount(1, $this->broadcast('all'));
    }

    private function assertSameCount(int $expected, Broadcast $broadcast): void
    {
        $resolver = app(BroadcastRecipientResolver::class);

        $this->assertSame($expected, $resolver->count($broadcast));
        $this->assertSame($expected, $resolver->resolve($broadcast)->count());
    }

    /**
     * @param  list<string>  $tags
     */
    private function broadcast(string $target, array $tags = [], ?string $segmentId = null): Broadcast
    {
        return (new Broadcast())->forceFill([
            'tenant_id'         => self::TENANT_ID,
            'assistant_id'      => (string) $this->assistant->getKey(),
            'target_type'       => $target,
            'target_tags'       => 'tags' === $target ? $tags : null,
            'target_segment_id' => $segmentId,
            'status'            => BroadcastStatus::Draft->value,
        ]);
    }

    private function channel(?Assistant $assistant = null, bool $active = true): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => ($assistant ?? $this->assistant)->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => $active,
        ]));
    }

    private function contact(): Contact
    {
        return Contact::factory()->forTenant(self::TENANT_ID)->create();
    }

    private function tag(Contact $contact, string $tag): void
    {
        ContactTag::query()->create(['contact_id' => $contact->getKey(), 'tag' => $tag, 'tagged_by' => null, 'tagged_at' => now()]);
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
