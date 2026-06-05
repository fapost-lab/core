<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactTag>
 */
final class ContactTagFactory extends Factory
{
    protected $model = ContactTag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'tag'        => fake()->unique()->word(),
            'tagged_by'  => fake()->uuid(),
            'tagged_at'  => now(),
        ];
    }
}
