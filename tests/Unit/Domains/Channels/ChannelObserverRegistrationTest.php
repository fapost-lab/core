<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Observers\ChannelObserver as RegistryChannelObserver;
use App\Domains\Messaging\Observers\ChannelObserver as MessagingChannelObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ChannelObserverRegistrationTest extends TestCase
{
    public function test_channel_registers_all_observers_via_observed_by_attribute(): void
    {
        $attributes = (new ReflectionClass(Channel::class))->getAttributes(ObservedBy::class);

        $this->assertCount(1, $attributes);

        /** @var ObservedBy $observedBy */
        $observedBy = $attributes[0]->newInstance();

        $this->assertSame(
            [RegistryChannelObserver::class, MessagingChannelObserver::class],
            $observedBy->classes,
        );
    }
}
