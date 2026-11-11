<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models\Builders;

use App\Domains\Flow\Enums\FlowKind;
use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
final class FlowDefinitionBuilder extends Builder
{
    /**
     * @return $this
     */
    public function active(): self
    {
        return $this->where('is_active', true);
    }

    /**
     * Definitions a session may be started on: flows, never templates. A template's definition is only ever
     * copied into a host flow at publish time.
     *
     * @return $this
     */
    public function runnable(): self
    {
        return $this->where('kind', FlowKind::Flow->value);
    }
}
