<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Contact\Enums\PlatformEnum;

/**
 * The platforms a contact can have today: every registered channel type, which is the platform of the contacts that
 * write through it, plus `email`, which Core knows without a channel.
 *
 * Contacts of a type whose integration is gone keep their stored platform; it is only no longer offered as a filter.
 */
final readonly class ContactPlatforms
{
    public function __construct(
        private ChannelRegistryInterface $channels,
    ) {
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_values(array_unique([...$this->channels->types(), PlatformEnum::Email->value]));
    }

    public function has(string $platform): bool
    {
        return in_array($platform, $this->values(), true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        return array_map(
            fn (string $platform): array => [
                'value' => $platform,
                'label' => null === PlatformEnum::tryFrom($platform)
                    ? $this->channels->label($platform)
                    : trans('console.contacts.platforms.' . $platform),
            ],
            $this->values(),
        );
    }
}
