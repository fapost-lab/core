<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\State\ChannelStateProjector;
use DateTimeInterface;
use Tests\Feature\FeatureTestCase;

final class ChannelStateProjectorTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_it_projects_the_bot_identity_of_the_contacts_channel(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $channel   = $this->channel($assistant->getKey(), 'fapost_demo_bot');

        $this->link($contact->getKey(), $channel->getKey(), now());

        $state = (new ChannelStateProjector())->project(
            (string)$contact->getKey(),
            (string)$assistant->getKey(),
        );

        $this->assertSame((string)$channel->getKey(), $state['id']);
        $this->assertSame('telegram', $state['type']);
        $this->assertSame('fapost_demo_bot', $state['bot_username']);
        $this->assertSame('@fapost_demo_bot', $state['bot_handle']);
        $this->assertSame('https://t.me/fapost_demo_bot', $state['link']);
    }

    public function test_it_prefers_the_most_recently_used_channel(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $stale  = $this->channel($assistant->getKey(), 'old_bot');
        $recent = $this->channel($assistant->getKey(), 'new_bot');

        $this->link($contact->getKey(), $stale->getKey(), now()->subDay());
        $this->link($contact->getKey(), $recent->getKey(), now());

        $state = (new ChannelStateProjector())->project(
            (string)$contact->getKey(),
            (string)$assistant->getKey(),
        );

        $this->assertSame('new_bot', $state['bot_username']);
    }

    public function test_it_ignores_inactive_channels(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $channel   = $this->channel($assistant->getKey(), 'disabled_bot', isActive: false);

        $this->link($contact->getKey(), $channel->getKey(), now());

        $state = (new ChannelStateProjector())->project(
            (string)$contact->getKey(),
            (string)$assistant->getKey(),
        );

        $this->assertSame([], $state);
    }

    public function test_it_returns_an_empty_projection_without_a_channel(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $state = (new ChannelStateProjector())->project(
            (string)$contact->getKey(),
            (string)$assistant->getKey(),
        );

        $this->assertSame([], $state);
    }

    public function test_it_projects_null_identity_before_the_provider_handshake(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $channel   = $this->channel($assistant->getKey(), null);

        $this->link($contact->getKey(), $channel->getKey(), now());

        $state = (new ChannelStateProjector())->project(
            (string)$contact->getKey(),
            (string)$assistant->getKey(),
        );

        $this->assertSame('telegram', $state['type']);
        $this->assertNull($state['bot_username']);
        $this->assertNull($state['link']);
    }

    private function channel(string $assistantId, ?string $username, bool $isActive = true): Channel
    {
        return Channel::withoutEvents(function () use ($assistantId, $username, $isActive): Channel {
            $channel = Channel::factory()->create([
                'assistant_id' => $assistantId,
                'tenant_id'    => self::TENANT_ID,
                'type'         => ChannelTypeEnum::Telegram,
                'is_active'    => $isActive,
            ]);

            $channel->forceFill(['telegram_bot_username' => $username])->save();

            return $channel;
        });
    }

    private function link(string $contactId, string $channelId, DateTimeInterface $lastInteractionAt): void
    {
        ChannelContact::query()->create([
            'contact_id'          => $contactId,
            'channel_id'          => $channelId,
            'last_interaction_at' => $lastInteractionAt,
        ]);
    }
}
