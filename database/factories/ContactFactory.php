<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
final class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'   => fake()->uuid(),
            'platform'    => PlatformEnum::Telegram,
            'external_id' => (string) fake()->unique()->numerify('##########'),
            'language'    => 'en',
            'meta'        => [],
            'attributes'  => [],
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenantId,
        ]);
    }
}
