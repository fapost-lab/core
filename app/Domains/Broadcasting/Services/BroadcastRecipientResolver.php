<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Services;

use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\ChannelContact;
use Illuminate\Support\Collection;

/**
 * Resolves the deliverable audience of a broadcast: one active channel binding
 * per contact under the broadcast's assistant (most-recently-interacted channel
 * wins), narrowed by the target selector. Mirrors the notify targeting so the
 * platform "cannot initiate first contact" constraint is respected — contacts
 * with no active channel binding are simply absent from the result.
 */
final readonly class BroadcastRecipientResolver
{
    public function __construct(
        private ContactTagRepositoryInterface $tags,
    ) {
    }

    /**
     * @return Collection<int, ChannelContact>
     */
    public function resolve(Broadcast $broadcast): Collection
    {
        $contactIds = $this->targetContactIds($broadcast);

        // Tag target that matched no contacts → nobody to deliver to.
        if (null !== $contactIds && [] === $contactIds) {
            return new Collection();
        }

        $query = ChannelContact::query()
            ->select('channel_contacts.*')
            ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
            ->where('channels.assistant_id', $broadcast->assistant_id)
            ->where('channels.is_active', true)
            ->with(['channel', 'contact'])
            ->orderByDesc('channel_contacts.last_interaction_at');

        if (null !== $contactIds) {
            $query->whereIn('channel_contacts.contact_id', $contactIds);
        }

        return $query->get()->unique('contact_id')->values();
    }

    /**
     * Contact-id filter for the target, or null to reach everyone.
     *
     * @return list<string>|null
     */
    private function targetContactIds(Broadcast $broadcast): ?array
    {
        if (BroadcastTarget::Tags !== $broadcast->target_type) {
            return null;
        }

        $ids = [];

        foreach ($broadcast->target_tags ?? [] as $tag) {
            if (is_string($tag) && '' !== $tag) {
                $ids = array_merge($ids, $this->tags->contactIdsWithTag($tag));
            }
        }

        return array_values(array_unique($ids));
    }
}
