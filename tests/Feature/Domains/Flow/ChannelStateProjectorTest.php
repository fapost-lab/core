<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\State\ChannelStateProjector;
use DateTimeInterface;
use Fapost\Foundation\Connector\ConnectorChannel;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Connectors\Echo\EchoChannelProfile;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;

final class ChannelStateProjectorTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_it_projects_the_bot_identity_of_the_contacts_channel(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $channel   = $this->channel($assistant->getKey(), 'fapost_demo_bot');

        $this->link($contact->getKey(), $channel->getKey(), now());

        $state = $this->app->make(ChannelStateProjector::class)->project(
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

        $state = $this->app->make(ChannelStateProjector::class)->project(
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

        $state = $this->app->make(ChannelStateProjector::class)->project(
            (string)$contact->getKey(),
            (string)$assistant->getKey(),
        );

        $this->assertSame([], $state);
    }

    public function test_it_returns_an_empty_projection_without_a_channel(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $state = $this->app->make(ChannelStateProjector::class)->project(
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

        $state = $this->app->make(ChannelStateProjector::class)->project(
            (string)$contact->getKey(),
            (string)$assistant->getKey(),
        );

        $this->assertSame('telegram', $state['type']);
        $this->assertNull($state['bot_username']);
        $this->assertNull($state['link']);
    }

    public function test_it_projects_the_link_a_connectors_profile_builds(): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class, profile: EchoChannelProfile::class),
            'echo',
        );
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => 'echo',
            'config'       => ['account_name' => 'acme'],
        ]));

        $this->link($contact->getKey(), $channel->getKey(), now());

        $state = $this->app->make(ChannelStateProjector::class)->project((string)$contact->getKey(), (string)$assistant->getKey());

        $this->assertSame('echo', $state['type']);
        $this->assertSame('acme', $state['bot_username']);
        $this->assertSame('@acme', $state['bot_handle']);
        $this->assertSame('https://echo.example/acme', $state['link']);
    }

    private function channel(string $assistantId, ?string $username, bool $isActive = true): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistantId,
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'config'       => null === $username ? [] : ['bot_username' => $username],
            'is_active'    => $isActive,
        ]));
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
