<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
final class AssistantBuilder extends Builder
{
    /**
     * @return $this
     */
    public function active(): self
    {
        return $this->where('is_active', true);
    }
}
