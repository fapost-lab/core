<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class ContactService implements ContactServiceInterface
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function findOrCreate(
        string $tenantId,
        PlatformEnum $platform,
        string $externalId,
        array $meta = [],
    ): Contact {
        try {
            return Contact::query()->firstOrCreate(
                [
                    'tenant_id'   => $tenantId,
                    'platform'    => $platform,
                    'external_id' => $externalId,
                ],
                [
                    'meta'       => $meta,
                    'attributes' => [],
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return Contact::query()
                ->where('tenant_id', $tenantId)
                ->where('platform', $platform)
                ->where('external_id', $externalId)
                ->firstOrFail();
        }
    }

    public function findOrCreateChannelContact(Contact $contact, string $channelId): ChannelContact
    {
        try {
            $channelContact = ChannelContact::query()->firstOrCreate(
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channelId,
                ],
                [
                    'last_interaction_at' => now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $channelContact = ChannelContact::query()
                ->where('contact_id', $contact->id)
                ->where('channel_id', $channelId)
                ->firstOrFail();
        }

        if ( ! $channelContact->wasRecentlyCreated) {
            $channelContact->last_interaction_at = now();
            $channelContact->save();
        }

        return $channelContact;
    }
}
