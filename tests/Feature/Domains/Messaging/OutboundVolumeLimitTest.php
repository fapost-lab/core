<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Messaging;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Broadcasting\Jobs\SendBroadcastRecipientJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Models\BroadcastRecipient;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeUsageMeter;

/**
 * Outbound message volume and call executions across the paths that spend them: the keys Core
 * registers, a broadcast that stops at the first refusal, and the builder's "Test call".
 */
final class OutboundVolumeLimitTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_core_registers_both_volume_keys_as_per_period(): void
    {
        $registry = $this->app->make(LimitRegistryInterface::class);

        $this->assertSame(LimitKind::PerPeriod, $registry->find('outbound_messages')?->kind);
        $this->assertSame(LimitKind::PerPeriod, $registry->find('call_executions')?->kind);
    }

    public function test_a_broadcast_stops_at_the_first_refusal_and_later_recipients_are_skipped_without_asking(): void
    {
        $sender                       = $this->refusingSender();
        [$broadcast, $first, $second] = $this->scenario(2);

        $this->send($first);

        $first->refresh();
        $broadcast->refresh();
        $this->assertSame(RecipientStatus::Skipped, $first->status);
        $this->assertSame('Limit "outbound_messages" reached: 10 of 10 this period.', $first->error);
        $this->assertSame(BroadcastStatus::Cancelled, $broadcast->status);
        $this->assertSame('limit_reached', $broadcast->stop_reason);
        $this->assertSame(1, $broadcast->skipped_count);

        $this->send($second);

        $second->refresh();
        $this->assertSame(RecipientStatus::Skipped, $second->status);
        $this->assertSame('limit_reached', $second->error);
        $this->assertSame(2, $broadcast->fresh()->skipped_count);
        $this->assertSame(1, $sender->calls, 'The operator is asked once, not once per remaining recipient.');
        $this->assertSame(BroadcastStatus::Cancelled, $broadcast->fresh()->status, 'Finishing the last recipient must not complete a stopped run.');
    }

    public function test_a_refusal_on_the_only_recipient_cancels_instead_of_completing(): void
    {
        $this->refusingSender();
        [$broadcast, $recipient] = $this->scenario(1);

        $this->send($recipient);

        $this->assertSame(BroadcastStatus::Cancelled, $broadcast->fresh()->status);
    }

    public function test_a_broadcast_message_is_counted_at_the_recipients_creation_time(): void
    {
        $sender = new class () implements MessageSenderInterface {
            /** @var list<OutboundMessage> */
            public array $sent = [];

            public function send(OutboundMessage $message): DeliveryResult
            {
                $this->sent[] = $message;

                return new DeliveryResult(sent: true, providerMessageId: 'p');
            }
        };
        $this->app->instance(MessageSenderInterface::class, $sender);

        [, $recipient] = $this->scenario(1);

        $this->send($recipient);

        $this->assertSame(
            $recipient->created_at?->toIso8601String(),
            $sender->sent[0]->metadata['volume_occurred_at'],
        );
    }

    public function test_test_call_in_the_builder_spends_a_call_execution(): void
    {
        Http::fake(['x.test/*' => Http::response(['ok' => true])]);
        $meter = FakeUsageMeter::allowing();
        $this->app->instance(UsageMeterInterface::class, $meter);

        $this->actingAs($this->builderUser())
            ->postJson('/builder/call/test', ['config' => ['transport' => 'http', 'target' => 'GET https://x.test/a']])
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $this->assertCount(1, $meter->units);
        $this->assertSame('call_executions', $meter->units[0]->key);
        $this->assertStringStartsWith('call_test:', $meter->units[0]->unitKey);
    }

    public function test_test_call_refused_by_the_limit_makes_no_request(): void
    {
        Http::fake();
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::denying(10, 10));

        $this->actingAs($this->builderUser())
            ->postJson('/builder/call/test', ['config' => ['transport' => 'http', 'target' => 'GET https://x.test/a']])
            ->assertOk()
            ->assertJsonPath('data.success', false)
            ->assertJsonPath('data.error_code', 'limit_reached');

        Http::assertNothingSent();
    }

    private function builderUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value, Permission::PublishFlow->value);

        return $user;
    }

    private function send(BroadcastRecipient $recipient): void
    {
        $job = new SendBroadcastRecipientJob(self::TENANT_ID, (string) $recipient->getKey());
        app()->call([$job, 'handle']);
    }

    private function refusingSender(): object
    {
        $sender = new class () implements MessageSenderInterface {
            public int $calls = 0;

            public function send(OutboundMessage $message): DeliveryResult
            {
                $this->calls++;

                throw new VolumeLimitReachedException('outbound_messages', 10, 10, 'Limit "outbound_messages" reached: 10 of 10 this period.');
            }
        };

        $this->app->instance(MessageSenderInterface::class, $sender);

        return $sender;
    }

    /**
     * @return array{0: Broadcast, 1: BroadcastRecipient, 2: BroadcastRecipient}
     */
    private function scenario(int $recipients): array
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        $broadcast = Broadcast::query()->create([
            'tenant_id'        => self::TENANT_ID,
            'assistant_id'     => $assistant->getKey(),
            'name'             => 'Promo',
            'message'          => 'Hello!',
            'target_type'      => 'all',
            'status'           => BroadcastStatus::Running->value,
            'total_recipients' => $recipients,
        ]);

        $created = [];

        for ($index = 0; $index < $recipients; $index++) {
            $contact = Contact::factory()->forTenant(self::TENANT_ID)->create([
                'external_id' => 'chat-' . $index,
                'language'    => 'en',
            ]);

            $created[] = BroadcastRecipient::query()->create([
                'tenant_id'    => self::TENANT_ID,
                'broadcast_id' => $broadcast->getKey(),
                'contact_id'   => $contact->getKey(),
                'channel_id'   => $channel->getKey(),
                'status'       => RecipientStatus::Pending->value,
            ]);
        }

        return [$broadcast, ...$created];
    }
}
