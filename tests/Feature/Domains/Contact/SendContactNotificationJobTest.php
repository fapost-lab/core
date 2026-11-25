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

/**
 * The deprecated fan-out of the retired `notify` contacts mode still delivers what an older release queued.
 */
final class SendContactNotificationJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    /**
     * `serialize()` of the job as the release that still dispatched it built it (tag target, a localized message),
     * base64-encoded. It is what a queued payload's command holds, so it must keep unserializing.
     */
    private const string QUEUED_BY_OLDER_RELEASE = 'Tzo1MToiQXBwXERvbWFpbnNcQ29udGFjdFxKb2JzXFNlbmRDb250YWN0Tm90aWZpY2F0aW9uSm9iIjo4OntzOjg6InRlbmFudElkIjtzOjI1OiIwMUpURU5BTlQwMDAwMDAwMDAwMDAwMDAwIjtzOjExOiJhc3Npc3RhbnRJZCI7czoxMToiYXNzaXN0YW50LTEiO3M6MTM6ImNvbnRhY3RUYXJnZXQiO3M6MzoidGFnIjtzOjQ6InRhZ3MiO2E6MTp7aTowO3M6MzoidmlwIjt9czo3OiJtZXNzYWdlIjthOjI6e3M6MjoiZW4iO3M6MjoiSGkiO3M6MjoicnUiO3M6MTI6ItCf0YDQuNCy0LXRgiI7fXM6OToic2Vzc2lvbklkIjtzOjk6InNlc3Npb24tMSI7czo2OiJub2RlSWQiO3M6Njoibm9kZS0xIjtzOjU6InF1ZXVlIjtzOjE5OiJtZXNzYWdpbmcuYnJvYWRjYXN0Ijt9';

    public function test_a_job_queued_by_an_older_release_unserializes_with_its_properties(): void
    {
        $job = unserialize(base64_decode(self::QUEUED_BY_OLDER_RELEASE, true));

        $this->assertInstanceOf(SendContactNotificationJob::class, $job);
        $this->assertSame('01JTENANT0000000000000000', $job->tenantId);
        $this->assertSame('assistant-1', $job->assistantId);
        $this->assertSame('tag', $job->contactTarget);
        $this->assertSame(['vip'], $job->tags);
        $this->assertSame(['en' => 'Hi', 'ru' => 'Привет'], $job->message);
        $this->assertSame('session-1', $job->sessionId);
        $this->assertSame('node-1', $job->nodeId);
        $this->assertSame('messaging.broadcast', $job->queue);
    }

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

    public function test_a_language_without_text_falls_back_to_the_base_language(): void
    {
        Bus::fake();

        $channel = $this->channel();
        $this->bind($this->contact(['language' => 'de']), $channel);

        $this->runJob($channel->assistant_id, 'all', [], ['ru' => 'Привет', 'en' => 'Hello']);

        Bus::assertDispatched(BroadcastSendJob::class, fn (BroadcastSendJob $job): bool => 'Hello' === $job->message->payload->text);
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
