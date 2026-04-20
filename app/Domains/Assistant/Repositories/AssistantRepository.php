<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Repositories;

use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Models\Assistant;

final class AssistantRepository implements AssistantRepositoryInterface
{
    public function findById(string $assistantId): Assistant
    {
        return Assistant::query()->findOrFail($assistantId);
    }
}
