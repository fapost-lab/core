<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MessengerPreference;
use App\Models\PreSaleRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PreSaleRequest> */
final class PreSaleRequestFactory extends Factory
{
    protected $model = PreSaleRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name'                 => fake()->name(),
            'company'              => fake()->company(),
            'email'                => fake()->safeEmail(),
            'messenger_preference' => fake()->randomElement(MessengerPreference::cases()),
            'message'              => fake()->optional()->paragraph(),
            'ip_address'           => fake()->ipv4(),
            'user_agent'           => fake()->userAgent(),
            'locale'               => fake()->randomElement(['en', 'ru', 'uk']),
        ];
    }
}
