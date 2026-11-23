<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Broadcasting\Events\BroadcastCompleted;
use App\Domains\Broadcasting\Jobs\SendBroadcastRecipientJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Models\BroadcastRecipient;
use App\Domains\Broadcasting\Services\BroadcastService;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Support\Facades\Event;
use Tests\Feature\FeatureTestCase;

final class SendBroadcastRecipientJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_delivers_records_outcome_and_completes_the_broadcast(): void
    {
        $this->fakeSender(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        [$broadcast, $recipient] = $this->scenario();

        $this->send($recipient);

        $recipient->refresh();
        $broadcast->refresh();
        $this->assertSame(RecipientStatus::Sent, $recipient->status);
        $this->assertSame('pmid-1', $recipient->provider_message_id);
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(BroadcastStatus::Completed, $broadcast->status, 'Last recipient must complete the broadcast.');
    }

    public function test_the_last_recipient_announces_the_completed_broadcast_once_with_final_counts(): void
    {
        Event::fake([BroadcastCompleted::class]);
        $this->fakeSender(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        [$broadcast, $recipient] = $this->scenario();

        $this->send($recipient);
        $this->send($recipient); // retry: the recipient is already settled

        Event::assertDispatchedTimes(BroadcastCompleted::class, 1);
        Event::assertDispatched(BroadcastCompleted::class, fn (BroadcastCompleted $event): bool => self::TENANT_ID === $event->tenantId
            && (string) $broadcast->getKey() === $event->broadcastId
            && 'Promo' === $event->name
            && 1 === $event->recipientsTotal
            && 1 === $event->sent
            && 0 === $event->failed);
    }

    public function test_a_recipient_that_is_not_the_last_announces_nothing(): void
    {
        Event::fake([BroadcastCompleted::class]);
        $this->fakeSender(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        [$broadcast, $recipient] = $this->scenario();
        $broadcast->update(['total_recipients' => 2]);

        $this->send($recipient);

        $this->assertSame(BroadcastStatus::Running, $broadcast->fresh()->status);
        Event::assertNotDispatched(BroadcastCompleted::class);
    }

    public function test_a_cancelled_broadcast_announces_no_completion(): void
    {
        Event::fake([BroadcastCompleted::class]);

        [$broadcast, $recipient] = $this->scenario();
        $broadcast->update(['status' => BroadcastStatus::Cancelled->value]);

        $this->send($recipient);

        Event::assertNotDispatched(BroadcastCompleted::class);
    }

    public function test_is_idempotent_when_recipient_already_processed(): void
    {
        $this->fakeSender(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        [$broadcast, $recipient] = $this->scenario();

        $this->send($recipient);
        $this->send($recipient); // retry

        $this->assertSame(1, $broadcast->fresh()->sent_count, 'A processed recipient must not be re-sent or re-counted.');
    }

    public function test_failed_delivery_is_recorded_as_failed(): void
    {
        $this->fakeSender(new DeliveryResult(sent: false, error: 'boom'));

        [$broadcast, $recipient] = $this->scenario();

        $this->send($recipient);

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Failed, $recipient->status);
        $this->assertSame('boom', $recipient->error);
        $this->assertSame(1, $broadcast->fresh()->failed_count);
    }

    public function test_skips_recipient_when_broadcast_is_cancelled(): void
    {
        $this->fakeSender(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        [$broadcast, $recipient] = $this->scenario();
        $broadcast->update(['status' => BroadcastStatus::Cancelled->value]);

        $this->send($recipient);

        $this->assertSame(RecipientStatus::Skipped, $recipient->fresh()->status);
        $this->assertSame(1, $broadcast->fresh()->skipped_count);
    }

    public function test_skips_recipient_when_broadcast_run_failed(): void
    {
        $sender = $this->fakeSenderCapturing(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        [$broadcast, $recipient] = $this->scenario();
        $broadcast->update([
            'status'      => BroadcastStatus::Failed->value,
            'stop_reason' => BroadcastStatus::STOP_REASON_RUN_FAILED,
        ]);

        $this->send($recipient);

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Skipped, $recipient->status);
        $this->assertSame('run_failed', $recipient->error);
        $this->assertCount(0, $sender->sent);
        $broadcast->refresh();
        $this->assertSame(1, $broadcast->skipped_count);
        $this->assertSame(BroadcastStatus::Failed, $broadcast->status);
    }

    public function test_delivers_the_locale_matching_the_contacts_language(): void
    {
        $sender = $this->fakeSenderCapturing(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        [, $recipient] = $this->scenario(
            message: ['en' => 'Hello!', 'ru' => 'Привет!'],
            contactLanguage: 'ru',
        );

        $this->send($recipient);

        $this->assertCount(1, $sender->sent);
        $this->assertSame('Привет!', $sender->sent[0]->payload->text);
    }

    public function test_falls_back_to_the_tenant_base_language_when_contact_language_is_unmapped(): void
    {
        // TenantSettings::content_base_language defaults to 'en'. The contact's
        // language ('fr') is neither blank (so the job passes it straight
        // through) nor present in the message map, so ContentTranslator's own
        // fallback chain must land on the base language entry.
        $sender = $this->fakeSenderCapturing(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        [, $recipient] = $this->scenario(
            message: ['en' => 'Hello!', 'ru' => 'Привет!'],
            contactLanguage: 'fr',
        );

        $this->send($recipient);

        $this->assertCount(1, $sender->sent);
        $this->assertSame('Hello!', $sender->sent[0]->payload->text);
    }

    public function test_a_send_in_flight_when_the_run_fails_moves_its_unit_out_of_skipped(): void
    {
        [$broadcast, $recipient] = $this->scenario();
        $broadcast->update(['total_recipients' => 1]);

        $this->app->instance(MessageSenderInterface::class, new class ((string) $broadcast->getKey()) implements MessageSenderInterface {
            public function __construct(private readonly string $broadcastId)
            {
            }

            public function send(OutboundMessage $message): DeliveryResult
            {
                // The run's failed() handler lands while the send is in flight.
                app(BroadcastService::class)->failRun($this->broadcastId);

                return new DeliveryResult(sent: true, providerMessageId: 'pmid-1');
            }
        });

        $this->send($recipient);

        $recipient->refresh();
        $broadcast->refresh();
        $this->assertSame(RecipientStatus::Sent, $recipient->status);
        $this->assertSame('pmid-1', $recipient->provider_message_id);
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(0, $broadcast->skipped_count);
        $this->assertSame(0, $broadcast->failed_count);
        $this->assertSame(BroadcastStatus::Failed, $broadcast->status);
        $this->assertCountsMatchTotal($broadcast);
    }

    public function test_a_run_that_fails_after_the_send_finished_leaves_the_settled_recipient_alone(): void
    {
        [$broadcast, $recipient] = $this->scenario();
        $broadcast->update(['total_recipients' => 1]);
        $this->fakeSender(new DeliveryResult(sent: true, providerMessageId: 'pmid-1'));

        $this->send($recipient);
        app(BroadcastService::class)->failRun((string) $broadcast->getKey());

        $broadcast->refresh();
        $this->assertSame(RecipientStatus::Sent, $recipient->fresh()->status);
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(0, $broadcast->skipped_count);
        $this->assertCountsMatchTotal($broadcast);
    }

    public function test_settling_a_recipient_twice_counts_it_once(): void
    {
        [$broadcast, $recipient] = $this->scenario();
        $broadcast->update(['total_recipients' => 1]);
        $service = app(BroadcastService::class);

        $this->assertTrue($service->settleRecipient((string) $broadcast->getKey(), (string) $recipient->getKey(), RecipientStatus::Sent));
        $this->assertFalse($service->settleRecipient((string) $broadcast->getKey(), (string) $recipient->getKey(), RecipientStatus::Failed));

        $broadcast->refresh();
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(0, $broadcast->failed_count);
        $this->assertCountsMatchTotal($broadcast);
    }

    public function test_a_failure_after_the_run_failed_is_counted_once_as_failed(): void
    {
        [$broadcast, $recipient] = $this->scenario();
        $broadcast->update(['total_recipients' => 1]);

        $this->app->instance(MessageSenderInterface::class, new class ((string) $broadcast->getKey()) implements MessageSenderInterface {
            public function __construct(private readonly string $broadcastId)
            {
            }

            public function send(OutboundMessage $message): DeliveryResult
            {
                app(BroadcastService::class)->failRun($this->broadcastId);

                return new DeliveryResult(sent: false, error: 'nope');
            }
        });

        $this->send($recipient);

        $broadcast->refresh();
        $this->assertSame(RecipientStatus::Failed, $recipient->fresh()->status);
        $this->assertSame(1, $broadcast->failed_count);
        $this->assertSame(0, $broadcast->skipped_count);
        $this->assertCountsMatchTotal($broadcast);
    }

    private function assertCountsMatchTotal(Broadcast $broadcast): void
    {
        $this->assertSame(
            $broadcast->total_recipients,
            $broadcast->sent_count + $broadcast->failed_count + $broadcast->skipped_count,
        );
    }

    private function send(BroadcastRecipient $recipient): void
    {
        $job = new SendBroadcastRecipientJob(self::TENANT_ID, (string) $recipient->getKey());
        app()->call([$job, 'handle']);
    }

    private function fakeSender(DeliveryResult $result): void
    {
        $this->app->bind(MessageSenderInterface::class, static fn (): MessageSenderInterface => new class ($result) implements MessageSenderInterface {
            public function __construct(private readonly DeliveryResult $result)
            {
            }

            public function send(OutboundMessage $message): DeliveryResult
            {
                return $this->result;
            }
        });
    }

    /**
     * Same fake as {@see fakeSender()} but records every outbound message so
     * assertions can inspect the resolved locale text.
     */
    private function fakeSenderCapturing(DeliveryResult $result): object
    {
        $sender = new class ($result) implements MessageSenderInterface {
            /** @var list<OutboundMessage> */
            public array $sent = [];

            public function __construct(private readonly DeliveryResult $result)
            {
            }

            public function send(OutboundMessage $message): DeliveryResult
            {
                $this->sent[] = $message;

                return $this->result;
            }
        };

        $this->app->instance(MessageSenderInterface::class, $sender);

        return $sender;
    }

    /**
     * @param  array<string, string>|string  $message
     * @return array{0: Broadcast, 1: BroadcastRecipient}
     */
    private function scenario(array|string $message = 'Hello!', string $contactLanguage = 'en'): array
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create([
            'external_id' => 'chat-1',
            'language'    => $contactLanguage,
        ]);

        $broadcast = Broadcast::query()->create([
            'tenant_id'        => self::TENANT_ID,
            'assistant_id'     => $assistant->getKey(),
            'name'             => 'Promo',
            'message'          => $message,
            'target_type'      => 'all',
            'status'           => BroadcastStatus::Running->value,
            'total_recipients' => 1,
        ]);

        $recipient = BroadcastRecipient::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'broadcast_id' => $broadcast->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'status'       => RecipientStatus::Pending->value,
        ]);

        return [$broadcast, $recipient];
    }
}
