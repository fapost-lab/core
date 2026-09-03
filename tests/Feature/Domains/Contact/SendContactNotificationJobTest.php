<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Contact;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Jobs\SendContactNotificationJob;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactTag;
use App\Jobs\Messaging\BroadcastSendJob;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\FeatureTestCase;

final class SendContactNotificationJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_tag_target_delivers_only_to_reachable_contacts(): void
    {
        Bus::fake();

        $channel = $this->channel();

        // Reachable: tagged + bound to the assistant's channel.
        $reachable = $this->contact();
        $this->bind($reachable, $channel);
        $this->tag($reachable, 'vip');

        // Tagged but unreachable: no channel binding for this assistant.
        $unreachable = $this->contact();
        $this->tag($unreachable, 'vip');

        // Bound but untagged: must be excluded from a tag-targeted send.
        $untagged = $this->contact();
        $this->bind($untagged, $channel);

        $this->runJob($channel->assistant_id, 'tag', ['vip'], 'Promo');

        Bus::assertDispatchedTimes(BroadcastSendJob::class, 1);
        Bus::assertDispatched(BroadcastSendJob::class, function (BroadcastSendJob $job) use ($reachable, $channel): bool {
            $message = $job->message;

            return $message instanceof OutboundMessage
                && $message->chatId === (string) $reachable->external_id
                && (string) $channel->getKey() === $message->channelId
                && 'telegram' === $message->channelType
                && 'Promo' === $message->payload->text;
        });
    }

    public function test_all_target_delivers_to_every_linked_contact(): void
    {
        Bus::fake();

        $channel = $this->channel();

        $this->bind($this->contact(), $channel);
        $this->bind($this->contact(), $channel);
        // Unlinked contact must not be reached.
        $this->contact();

        $this->runJob($channel->assistant_id, 'all', [], 'Hello');

        Bus::assertDispatchedTimes(BroadcastSendJob::class, 2);
    }

    public function test_is_idempotent_on_retry(): void
    {
        Bus::fake();

        $channel = $this->channel();
        $this->bind($this->contact(), $channel);

        $session = (string) \Illuminate\Support\Str::uuid();

        $this->runJob($channel->assistant_id, 'all', [], 'Hello', $session);
        $this->runJob($channel->assistant_id, 'all', [], 'Hello', $session);

        Bus::assertDispatchedTimes(BroadcastSendJob::class, 1);
    }

    public function test_resolves_content_language_per_recipient(): void
    {
        Bus::fake();

        $channel = $this->channel();
        $contact = $this->contact(['language' => 'ru']);
        $this->bind($contact, $channel);

        $this->runJob($channel->assistant_id, 'all', [], ['en' => 'Welcome', 'ru' => 'Добро пожаловать']);

        Bus::assertDispatched(BroadcastSendJob::class, fn (BroadcastSendJob $job): bool => 'Добро пожаловать' === $job->message->payload->text);
    }

    private function channel(): Channel
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);

        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->id,
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->forTenant(self::TENANT_ID)->create($attributes);
    }

    private function bind(Contact $contact, Channel $channel): void
    {
        ChannelContact::query()->create([
            'contact_id'          => $contact->id,
            'channel_id'          => $channel->id,
            'last_interaction_at' => now(),
        ]);
    }

    private function tag(Contact $contact, string $tag): void
    {
        ContactTag::query()->create([
            'contact_id' => $contact->id,
            'tag'        => $tag,
            'tagged_by'  => null,
            'tagged_at'  => now(),
        ]);
    }

    /**
     * @param  list<string>                  $tags
     * @param  string|array<string, string>  $message
     */
    private function runJob(string $assistantId, string $target, array $tags, string|array $message, ?string $sessionId = null): void
    {
        $job = new SendContactNotificationJob(
            tenantId: self::TENANT_ID,
            assistantId: $assistantId,
            contactTarget: $target,
            tags: $tags,
            message: $message,
            sessionId: $sessionId ?? (string) \Illuminate\Support\Str::uuid(),
            nodeId: 'node-notify',
        );

        app()->call([$job, 'handle']);
    }
}
