<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Broadcasting\Jobs\SendBroadcastRecipientJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Models\BroadcastRecipient;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
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
