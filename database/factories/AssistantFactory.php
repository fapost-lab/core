<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Assistant\Models\Assistant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assistant>
 */
final class AssistantFactory extends Factory
{
    protected $model = Assistant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'        => '00000000-0000-0000-0000-000000000001',
            'name'             => 'Assistant',
            'is_active'        => true,
            'default_language' => 'en',
            'default_flow_id'  => null,
            'fallback_message' => null,
            'settings'         => [],
        ];
    }
}
