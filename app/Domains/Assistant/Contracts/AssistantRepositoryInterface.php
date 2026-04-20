<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Contracts;

use App\Domains\Assistant\Models\Assistant;

interface AssistantRepositoryInterface
{
    public function findById(string $assistantId): Assistant;
}
