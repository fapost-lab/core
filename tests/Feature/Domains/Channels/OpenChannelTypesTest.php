<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Console\Platform\TenantUnservedChannelsFinder;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelPublicLinks;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\DTO\InboundWebhookPayload;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\Fixtures\Connectors\Echo\EchoSignedInboundAdapter;
use Tests\Support\FakeUsageMeter;

/**
 * A channel type is a string owned by an integration: a connector's channel runs the inbound path like a built-in
 * one, and a channel whose integration is gone stays readable and is dropped quietly.
 */
final class OpenChannelTypesTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    /** @var list<MessageLogEntry> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::allowing());
        $this->app->instance(ConversationLoggerInterface::class, new class ($this->logged) implements ConversationLoggerInterface {
            /** @param list<MessageLogEntry> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log(MessageLogEntry $entry): void
            {
                $this->logged[] = $entry;
            }

            public function updateDeliveryStatus(string $providerMessageId, DeliveryStatus $status): void
            {
            }
        });
    }

    public function test_an_inbound_message_of_a_connector_channel_creates_a_contact_of_the_connectors_platform(): void
    {
        $this->registerEcho();
        $channel = $this->channel('echo');

        $this->deliver($channel, 'echo', ['id' => 'm1', 'from' => 'user-7', 'text' => 'hi']);

        $contact = Contact::query()->sole();

        $this->assertSame('echo', $contact->platform);
        $this->assertSame('user-7', $contact->external_id);
        $this->assertSame(1, ChannelContact::query()->count());
        $this->assertCount(1, $this->logged);
        $this->assertSame('echo', $this->logged[0]->platform);
    }

    public function test_a_delivery_the_adapter_ignores_leaves_no_contact_transcript_or_retry(): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoSignedInboundAdapter::class),
            'echo',
            'Echo',
        );
        $channel = $this->channel('echo');

        Log::shouldReceive('debug')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => 'echo' === $context['platform']
                && self::TENANT_ID === $context['tenant_id']
                && (string) $channel->getKey() === $context['channel_id']
                && str_contains($context['reason'], 'own message'));

        $this->deliver($channel, 'echo', ['event_id' => 'e1', 'from' => 'bot', 'bot' => true]);

        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, ChannelContact::query()->count());
        $this->assertSame([], $this->logged);
    }

    public function test_an_inbound_message_for_a_type_nothing_serves_is_dropped_with_a_warning(): void
    {
        $channel = $this->channel('viber');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => 'viber' === $context['platform'] && self::TENANT_ID === $context['tenant_id']);

        $this->deliver($channel, 'viber', ['id' => 'm1', 'from' => 'user-7']);

        $this->assertSame(0, Contact::query()->count());
        $this->assertSame([], $this->logged);
    }

    public function test_a_channel_of_a_type_nothing_serves_still_reads(): void
    {
        $channel = $this->channel('viber');

        $found = Channel::query()->findOrFail($channel->getKey());

        $this->assertSame('viber', $found->type);
        $this->assertSame('viber', $found->getType());
        $this->assertNull($this->app->make(ChannelPublicLinks::class)->for($found));
    }

    public function test_the_finder_counts_channels_of_unserved_types_per_tenant(): void
    {
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
        $this->registerEcho();
        $this->channel('viber');
        $this->channel('viber');
        $this->channel('echo');
        $this->channel('telegram');

        $found = $this->app->make(TenantUnservedChannelsFinder::class)->find();

        $this->assertCount(1, $found);
        $this->assertSame('viber', $found[0]['type']);
        $this->assertSame(2, $found[0]['count']);
        $this->assertNotSame('', $found[0]['tenant']);
    }

    public function test_a_connector_type_of_the_longest_allowed_name_is_stored(): void
    {
        $channel = $this->channel(str_repeat('a', 32));

        $this->assertSame(str_repeat('a', 32), $channel->fresh()->type);
    }

    /**
     * SQLite keeps no length for a string column, so the widths can only be read from PostgreSQL.
     */
    public function test_the_columns_are_32_characters_wide_on_postgresql(): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('Column widths are only recorded by PostgreSQL.');
        }

        $width = fn (string $table, string $column): int => (int) DB::selectOne(
            'select character_maximum_length as width from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?',
            [$table, $column],
        )->width;

        $this->assertSame(32, $width('channels', 'type'));
        $this->assertSame(32, $width('contacts', 'platform'));
    }

    private function registerEcho(): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class),
            'echo',
            'Echo',
        );
    }

    private function channel(string $type): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => $type,
            'token'        => 'token',
            'is_active'    => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function deliver(Channel $channel, string $platform, array $raw): void
    {
        IncomingMessageJob::dispatchSync(new InboundWebhookPayload(
            tenantId: self::TENANT_ID,
            schema: 'main',
            assistantId: (string) $this->assistant->getKey(),
            channelId: (string) $channel->getKey(),
            platform: $platform,
            rawPayload: $raw,
            idempotencyKey: $platform . ':' . $channel->getKey() . ':' . ($raw['id'] ?? ''),
            receivedAt: 1_700_000_000,
        ));
    }
}
