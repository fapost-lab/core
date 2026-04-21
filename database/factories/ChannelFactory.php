<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Models\Channel;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
final class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assistant_id' => Assistant::factory(),
            'tenant_id'    => '00000000-0000-0000-0000-000000000001',
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'factory-token',
            'secret_token' => 'factory-secret-token',
            'config'       => [],
            'is_active'    => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Channel $channel): void {
            if ('' === (string) $channel->webhook_public_hash) {
                $channel->forceFill(['webhook_public_hash' => Str::random(48)]);
            }
        });
    }
}
