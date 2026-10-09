<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The ids of the flows to delete in one go.
 */
final class DestroyFlowsRequest extends FormRequest
{
    public const int MAX_IDS = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('deleteAny', FlowDraft::class) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1', 'max:' . self::MAX_IDS],
            'ids.*' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_map('strval', (array) $this->validated('ids')));
    }
}
