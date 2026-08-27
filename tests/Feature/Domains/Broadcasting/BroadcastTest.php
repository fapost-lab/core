<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use Tests\Feature\FeatureTestCase;

final class BroadcastTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_message_is_cast_to_a_locale_map_array(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        $broadcast = Broadcast::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'name'         => 'Promo',
            'message'      => ['en' => 'Hello!', 'ru' => 'Привет!'],
            'target_type'  => 'all',
            'status'       => BroadcastStatus::Draft->value,
        ]);

        $this->assertSame(
            ['en' => 'Hello!', 'ru' => 'Привет!'],
            $broadcast->fresh()->message,
        );
    }

    public function test_message_is_nullable(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        $broadcast = Broadcast::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'name'         => 'Promo',
            'message'      => null,
            'target_type'  => 'all',
            'status'       => BroadcastStatus::Draft->value,
        ]);

        $this->assertNull($broadcast->fresh()->message);
    }
}
