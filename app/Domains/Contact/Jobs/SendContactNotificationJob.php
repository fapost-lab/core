<?php

declare(strict_types=1);

namespace App\Domains\Contact\Jobs;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Enums\ContactNotifyTarget;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Jobs\Messaging\BroadcastSendJob;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;

/**
 * Fans a `notify` node's contacts-mode message out to an assistant's audience.
 *
 * Raised by {@see \App\Domains\Flow\Handlers\NotifyNodeHandler} once the flow
 * has composed the notification text. Runs on the low-priority broadcast queue,
 * re-establishes tenant context (queue workers carry no HTTP tenant middleware),
 * resolves the recipient set (by tag / all) for the target assistant, and emits
 * one {@see BroadcastSendJob} per deliverable contact.
 *
 * Platform constraint: a contact can only be messaged on a channel they have
 * already interacted with — i.e. an active {@see ChannelContact} must exist for
 * the assistant. Tag-targeted contacts without such a binding are skipped and
 * logged. Per-recipient content language is resolved through the content
 * translator (unlike staff mode, which uses the admin-UI language).
 *
 * A Redis-backed guard keyed on (session, node) keeps a retried fan-out from
 * double-enqueuing; the per-message idempotency key dedupes the actual sends.
 */
final class SendContactNotificationJob implements ShouldQueue, TenantAccessGatedJob
{
    use Queueable;

    /**
     * @param  list<string>   $tags     Tag filter — only used when {@see $contactTarget} is `tag`.
     * @param  string|array<string, string>  $message  Rendered text, or a localized {lang: text} map.
     */
    public function __construct(
        public string $tenantId,
        public string $assistantId,
        public string $contactTarget,
        public array $tags,
        public string|array $message,
        public string $sessionId,
        public string $nodeId,
    ) {
        $this->onQueue('messaging.broadcast');
    }

    public function accessModeTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Drop)];
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $tenantSwitcher,
        Dispatcher $dispatcher,
        ContactTagRepositoryInterface $tagRepository,
        ContentTranslatorInterface $translator,
        LoggerInterface $logger,
    ): void {
        // Retry-safety: only the first attempt for this (session, node) fans out.
        $guardKey = "contact_notify:{$this->tenantId}:{$this->sessionId}:{$this->nodeId}";

        if (! Cache::add($guardKey, true, now()->addDay())) {
            return;
        }

        $tenant = $tenants->getById($this->tenantId);

        $tenantSwitcher->runForTenant($tenant, function () use ($dispatcher, $tagRepository, $translator, $logger): void {
            $assistant = Assistant::query()->find($this->assistantId);

            if (! $assistant instanceof Assistant) {
                $logger->warning('notify contacts: assistant not found', [
                    'assistant' => $this->assistantId,
                    'session'   => $this->sessionId,
                    'node'      => $this->nodeId,
                ]);

                return;
            }

            $recipients = $this->resolveRecipients($tagRepository, $logger);

            if ($recipients->isEmpty()) {
                return;
            }

            $fallbackLanguage = app(TenantSettings::class)->fallback_language;

            foreach ($recipients as $channelContact) {
                $channel = $channelContact->channel;
                $contact = $channelContact->contact;

                if (null === $channel || null === $contact) {
                    continue;
                }

                $language = $this->resolveLanguage(
                    (string) $contact->language,
                    (string) $assistant->default_language,
                    $fallbackLanguage,
                );

                $text = $translator->resolveField($this->message, $language);

                if ('' === mb_trim($text)) {
                    continue;
                }

                $dispatcher->dispatch(new BroadcastSendJob(new OutboundMessage(
                    idempotencyKey: "{$this->sessionId}:{$this->nodeId}:notify:{$contact->getKey()}",
                    tenantId: $this->tenantId,
                    channelId: (string) $channel->getKey(),
                    channelType: $channel->type->value,
                    transportToken: $channel->token,
                    chatId: (string) $contact->external_id,
                    payload: new MessagePayload(type: 'text', text: $text),
                    metadata: [
                        'flow_session_id' => $this->sessionId,
                        'source'          => 'notify',
                        // Transcript-capture context for MessageSender (spec §7.4).
                        'contact_id'   => (string) $contact->getKey(),
                        'assistant_id' => $this->assistantId,
                        'origin'       => MessageOrigin::Notify->value,
                        'origin_ref'   => [
                            'flow_session_id' => $this->sessionId,
                            'node_id'         => $this->nodeId,
                        ],
                    ],
                )));
            }
        });
    }

    /**
     * Resolve the deliverable recipient set for this notification — channel
     * contacts under the target assistant, optionally narrowed to the tagged
     * audience. Tagged contacts that cannot be reached (no active channel
     * binding) are skipped and logged.
     *
     * @return Collection<int, ChannelContact>
     */
    private function resolveRecipients(ContactTagRepositoryInterface $tagRepository, LoggerInterface $logger): Collection
    {
        if (ContactNotifyTarget::All->value === $this->contactTarget) {
            return $this->deliverableChannelContacts(null);
        }

        $tagContactIds = $this->taggedContactIds($tagRepository);

        if ([] === $tagContactIds) {
            $logger->info('notify contacts: no contacts match the configured tags', [
                'session' => $this->sessionId,
                'node'    => $this->nodeId,
                'tags'    => $this->tags,
            ]);

            return new Collection();
        }

        $deliverable = $this->deliverableChannelContacts($tagContactIds);

        $skipped = count(array_diff($tagContactIds, $deliverable->pluck('contact_id')->all()));

        if ($skipped > 0) {
            // Cannot initiate first contact on TG/WA — these contacts have no
            // active channel binding for the target assistant.
            $logger->info('notify contacts: skipped undeliverable contacts', [
                'session' => $this->sessionId,
                'node'    => $this->nodeId,
                'skipped' => $skipped,
            ]);
        }

        return $deliverable;
    }

    /**
     * Union of contact ids carrying any of the configured tags.
     *
     * @return list<string>
     */
    private function taggedContactIds(ContactTagRepositoryInterface $tagRepository): array
    {
        $ids = [];

        foreach ($this->tags as $tag) {
            $ids = array_merge($ids, $tagRepository->contactIdsWithTag($tag));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Active channel bindings for the assistant, one per contact (the most
     * recently interacted-with channel wins), eager-loading channel + contact.
     *
     * @param  list<string>|null  $contactIds  Restrict to these contacts, or null for all.
     * @return Collection<int, ChannelContact>
     */
    private function deliverableChannelContacts(?array $contactIds): Collection
    {
        $query = ChannelContact::query()
            ->select('channel_contacts.*')
            ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
            ->where('channels.assistant_id', $this->assistantId)
            ->where('channels.is_active', true)
            ->with(['channel', 'contact'])
            ->orderByDesc('channel_contacts.last_interaction_at');

        if (null !== $contactIds) {
            $query->whereIn('channel_contacts.contact_id', $contactIds);
        }

        return $query->get()->unique('contact_id')->values();
    }

    /**
     * Content-language chain for a recipient: contact language → assistant
     * default → tenant fallback.
     */
    private function resolveLanguage(string $contactLanguage, string $assistantLanguage, string $fallback): string
    {
        if ('' !== $contactLanguage) {
            return $contactLanguage;
        }

        if ('' !== $assistantLanguage) {
            return $assistantLanguage;
        }

        return $fallback;
    }
}
