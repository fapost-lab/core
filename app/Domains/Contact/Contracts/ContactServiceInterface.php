<?php

declare(strict_types=1);

namespace App\Domains\Contact\Contracts;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;

interface ContactServiceInterface
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function findOrCreate(
        string $tenantId,
        PlatformEnum $platform,
        string $externalId,
        array $meta = [],
    ): Contact;

    public function findOrCreateChannelContact(Contact $contact, string $channelId): ChannelContact;
}
