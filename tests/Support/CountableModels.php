<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\AssistantService;

/**
 * Models whose count a tenant limit caps. Every creation path of these must go through the
 * service that checks the limit; {@see \Tests\Unit\Architecture\CountableModelCreationTest}
 * scans `app/` for the ones that do not.
 *
 * Add a model here when a limit starts counting it.
 */
final class CountableModels
{
    /**
     * Countable model => its only creator and the name of the relation that points at it.
     *
     * @return array<class-string, array{creator: class-string, relation: string}>
     */
    public static function models(): array
    {
        return [
            Assistant::class => ['creator' => AssistantService::class, 'relation' => 'assistants'],
        ];
    }
}
