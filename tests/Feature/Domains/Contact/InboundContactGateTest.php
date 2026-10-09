<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Contact;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\LimitRefusal;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use Fapost\Foundation\DTO\InboundWebhookPayload;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeUsageMeter;

/**
 * The active-contact gate in the inbound job: a sender the operator refuses leaves no contact, no
 * transcript and no flow, only a refusal record; an allowed sender goes the usual way.
 */
final class InboundContactGateTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Channel $channel;

    /** @var list<object> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $assistant     = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);
        $this->channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        $this->app->instance(ConversationLoggerInterface::class, new class ($this->logged) implements ConversationLoggerInterface {
            /** @param list<object> $logged */
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

    public function test_the_key_is_registered_as_a_per_period_limit(): void
    {
        $definition = $this->app->make(LimitRegistryInterface::class)->find('monthly_active_contacts');

        $this->assertNotNull($definition);
        $this->assertSame(LimitKind::PerPeriod, $definition->kind);
    }

    public function test_a_refused_stranger_leaves_no_contact_transcript_or_flow(): void
    {
        $meter = $this->meter(FakeUsageMeter::denying(100, 100));

        $this->run42();

        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, ChannelContact::query()->count());
        $this->assertSame(0, DB::table('flow_sessions')->count());
        $this->assertSame([], $this->logged);
        $this->assertCount(1, $meter->units);
    }

    public function test_a_refused_known_contact_is_not_logged_and_does_not_change(): void
    {
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create(['platform' => 'telegram', 'external_id' => '42']);
        $before  = $contact->updated_at;
        $this->meter(FakeUsageMeter::denying());

        $this->run42();

        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(0, ChannelContact::query()->count());
        $this->assertSame(0, DB::table('flow_sessions')->count());
        $this->assertSame([], $this->logged);
        $this->assertEquals($before, $contact->fresh()->updated_at);
    }

    public function test_an_allowed_sender_goes_the_usual_way(): void
    {
        $this->meter(FakeUsageMeter::allowing());

        $this->run42();

        $this->assertSame(1, Contact::query()->where('external_id', '42')->count());
        $this->assertSame(1, ChannelContact::query()->count());
        $this->assertCount(1, $this->logged);
        $this->assertSame(0, LimitRefusal::query()->count());
    }

    public function test_a_retry_asks_the_operator_with_the_same_unit_key_and_time(): void
    {
        $meter = $this->meter(FakeUsageMeter::allowing());

        $this->run42();
        $this->run42();

        $this->assertCount(2, $meter->units);
        $this->assertSame($meter->units[0]->unitKey, $meter->units[1]->unitKey);
        $this->assertEquals($meter->units[0]->occurredAt, $meter->units[1]->occurredAt);
        $this->assertSame(1_700_000_000, $meter->units[0]->occurredAt->getTimestamp());
        $this->assertSame('monthly_active_contacts', $meter->units[0]->key);
        $this->assertSame(self::TENANT_ID, $meter->units[0]->tenantId);
        $this->assertSame('contact:' . hash('sha256', self::TENANT_ID . '|telegram|42'), $meter->units[0]->unitKey);
    }

    public function test_other_users_get_other_unit_keys_and_the_key_hides_the_user_id(): void
    {
        $meter = $this->meter(FakeUsageMeter::allowing());

        $this->run42();
        $this->dispatch(43);

        $this->assertNotSame($meter->units[0]->unitKey, $meter->units[1]->unitKey);
        $this->assertStringNotContainsString('42', $meter->units[0]->unitKey);
    }

    public function test_a_refusal_is_kept_per_day_and_counts_attempts(): void
    {
        $this->meter(FakeUsageMeter::denying());

        $this->run42();
        $this->dispatch(42, 'up-2');
        $this->dispatch(43, 'up-3');

        $rows = LimitRefusal::query()->orderBy('attempts', 'desc')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]->attempts);
        $this->assertSame(hash('sha256', self::TENANT_ID . '|telegram|42'), $rows[0]->subject_hash);
        $this->assertSame('monthly_active_contacts', $rows[0]->limit_key);
        $this->assertSame((string) $this->channel->getKey(), $rows[0]->channel_id);
    }

    public function test_a_person_refused_at_two_channels_is_kept_per_channel(): void
    {
        $other = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->channel->assistant_id,
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'other-token',
            'is_active'    => true,
        ]));
        $this->meter(FakeUsageMeter::denying());

        $this->run42();
        IncomingMessageJob::dispatchSync(new InboundWebhookPayload(
            tenantId: self::TENANT_ID,
            schema: 'main',
            assistantId: (string) $other->assistant_id,
            channelId: (string) $other->getKey(),
            platform: 'telegram',
            rawPayload: ['update_id' => 2, 'message' => ['message_id' => 2, 'date' => 1_700_000_000, 'from' => ['id' => 42, 'first_name' => 'A'], 'chat' => ['id' => 42, 'type' => 'private'], 'text' => 'hi']],
            idempotencyKey: 'tg:' . $other->getKey() . ':up-1',
            receivedAt: 1_700_000_000,
        ));

        $this->assertSame(2, LimitRefusal::query()->count());
        $this->assertSame(1, LimitRefusal::query()->where('channel_id', (string) $other->getKey())->count());
    }

    public function test_a_refusal_is_logged_without_the_senders_identity(): void
    {
        $this->meter(FakeUsageMeter::denying(100, 100));
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('quota.inbound_refused', [
            'tenant_id'  => self::TENANT_ID,
            'channel_id' => (string) $this->channel->getKey(),
            'limit_key'  => 'monthly_active_contacts',
            'limit'      => 100,
            'used'       => 100,
        ]);
        $this->app->instance(LoggerInterface::class, $logger);

        $this->run42();
    }

    public function test_an_operator_failure_lets_the_message_through(): void
    {
        $this->meter(FakeUsageMeter::failing(new RuntimeException('operator is down')));
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $this->run42();

        $this->assertSame(['operator is down'], $reported);

        $this->assertSame(1, Contact::query()->count());
        $this->assertCount(1, $this->logged);
        $this->assertSame(0, LimitRefusal::query()->count());
    }

    private function meter(FakeUsageMeter $meter): FakeUsageMeter
    {
        $this->app->instance(UsageMeterInterface::class, $meter);

        return $meter;
    }

    private function run42(): void
    {
        $this->dispatch(42);
    }

    private function dispatch(int $userId, string $update = 'up-1'): void
    {
        IncomingMessageJob::dispatchSync(new InboundWebhookPayload(
            tenantId: self::TENANT_ID,
            schema: 'main',
            assistantId: (string) $this->channel->assistant_id,
            channelId: (string) $this->channel->getKey(),
            platform: 'telegram',
            rawPayload: [
                'update_id' => 1,
                'message'   => ['message_id' => 1, 'date' => 1_700_000_000, 'from' => ['id' => $userId, 'first_name' => 'A'], 'chat' => ['id' => $userId, 'type' => 'private'], 'text' => 'hi'],
            ],
            idempotencyKey: 'tg:' . $this->channel->getKey() . ':' . $update,
            receivedAt: 1_700_000_000,
        ));
    }
}
